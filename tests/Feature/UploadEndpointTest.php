<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use App\Services\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diskRoot = storage_path('app/image-disk-test-' . bin2hex(random_bytes(6)));
        Config::set('filesystems.disks.image', ['driver' => 'local', 'root' => $this->diskRoot, 'throw' => false]);
        Config::set('image.dev_put_enabled', true); // PHPUnit 是 testing 环境，显式开启 dev 直传
        Storage::forgetDisk('image');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    private function auth(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function makeJpeg(int $w, int $h): string
    {
        $path = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
        imagejpeg(imagecreatetruecolor($w, $h), $path);

        return $path;
    }

    private function pendingUpload(string $mime = 'image/jpeg', int $size = 10): string
    {
        $key = 'uploads/_pending/banner/' . Str::uuid() . '.jpg';
        Upload::create(['key' => $key, 'type' => 'banner', 'mime' => $mime, 'size' => $size, 'status' => 'pending']);

        return $key;
    }

    public function test_presign_返回key与上传地址并建记录(): void
    {
        $this->auth();

        $res = $this->postJson('/api/admin/uploads/presign', [
            'filename' => 'hero.jpg',
            'type'     => 'banner',
            'mime'     => 'image/jpeg',
            'size'     => 12345,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('expires_in', 600)
            ->assertJsonPath('auth_required', true) // dev 分支需要 Bearer
            ->assertJsonPath('key', fn ($k) => str_starts_with((string) $k, 'uploads/_pending/banner/'))
            ->assertJsonPath('upload_url', fn ($u) => str_contains((string) $u, 'dev-put'));

        $key = $res->json('key');
        $this->assertDatabaseHas('uploads', ['key' => $key, 'status' => 'pending']);
    }

    public function test_presign_拒绝非法类型与mime不匹配(): void
    {
        $this->auth();

        $this->postJson('/api/admin/uploads/presign', [
            'filename' => 'x.jpg', 'type' => 'hacker', 'mime' => 'image/jpeg', 'size' => 10,
        ])->assertStatus(422);

        $this->postJson('/api/admin/uploads/presign', [
            'filename' => 'x.png', 'type' => 'banner', 'mime' => 'image/jpeg', 'size' => 10,
        ])->assertStatus(422);
    }

    public function test_dev_put_需要有效签名且开关关闭时404(): void
    {
        $this->auth();

        $key = $this->pendingUpload();

        // 无签名 → 403
        $this->call('PUT', '/api/admin/uploads/dev-put?key=' . urlencode($key), [], [], [], [], 'x')
            ->assertStatus(403);

        // 有效 signed URL → 200 且对象写入
        $signedUrl = URL::temporarySignedRoute('admin.uploads.dev-put', now()->addMinutes(10), ['key' => $key]);
        $this->call('PUT', $signedUrl, [], [], [], [], 'fake-jpeg-bytes')->assertOk();

        $this->assertTrue(Storage::disk('image')->exists($key));

        // 开关关闭 → 404
        Config::set('image.dev_put_enabled', false);
        $signedUrl2 = URL::temporarySignedRoute('admin.uploads.dev-put', now()->addMinutes(10), ['key' => $key]);
        $this->call('PUT', $signedUrl2, [], [], [], [], 'x')->assertStatus(404);
    }

    public function test_confirm_成功_幂等重试返回相同结果(): void
    {
        $this->auth();

        $tmp = $this->makeJpeg(1600, 800);
        $size = filesize($tmp);
        $key = $this->pendingUpload('image/jpeg', $size);
        Storage::disk('image')->put($key, (string) file_get_contents($tmp));
        @unlink($tmp);

        $res = $this->postJson('/api/admin/uploads/confirm', ['key' => $key]);
        $res->assertStatus(200)->assertJsonPath('width', 1600)->assertJsonPath('height', 800);

        $final = ltrim((string) $res->json('url'), '/');
        $this->assertMatchesRegularExpression('#^uploads/banner/\d{8}/#', $final);
        $this->assertTrue(Storage::disk('image')->exists($final));
        $this->assertTrue(Storage::disk('image')->exists(ltrim((string) $res->json('thumb_url'), '/')));
        // 三档恒生成
        $this->assertTrue(Storage::disk('image')->exists(str_replace('.jpg', '_480.webp', $final)));
        $this->assertTrue(Storage::disk('image')->exists(str_replace('.jpg', '_960.webp', $final)));
        $this->assertTrue(Storage::disk('image')->exists(str_replace('.jpg', '_1600.webp', $final)));
        // pending 对象已删
        $this->assertFalse(Storage::disk('image')->exists($key));

        // 幂等重试：返回相同结果（不再依赖 pending 对象存在）
        $retry = $this->postJson('/api/admin/uploads/confirm', ['key' => $key]);
        $retry->assertStatus(200)->assertJsonPath('url', $res->json('url'));
    }

    public function test_confirm_并发中返回409_崩溃残留可抢占(): void
    {
        $this->auth();

        // 正在处理中（未超时）→ 409
        $key = $this->pendingUpload();
        Upload::where('key', $key)->update(['status' => 'processing', 'processing_at' => now()]);
        $this->postJson('/api/admin/uploads/confirm', ['key' => $key])->assertStatus(409);

        // 崩溃残留（processing_at 超 5 分钟）→ 抢占：记录可继续走确认流程
        $tmp = $this->makeJpeg(800, 800);
        $size = filesize($tmp);
        Storage::disk('image')->put($key, (string) file_get_contents($tmp));
        @unlink($tmp);
        Upload::where('key', $key)->update(['status' => 'processing', 'processing_at' => now()->subMinutes(6), 'size' => $size]);

        $this->postJson('/api/admin/uploads/confirm', ['key' => $key])->assertStatus(200);
        $this->assertDatabaseHas('uploads', ['key' => $key, 'status' => 'confirmed']);
    }

    public function test_confirm_内容与声明类型不符_422且failed_重试稳定422(): void
    {
        $this->auth();

        // 声明 jpeg，实际写 png 字节（size 声明为真实大小，让校验走到 finfo 分支）
        $key = 'uploads/_pending/banner/' . Str::uuid() . '.jpg';
        $tmp = tempnam(sys_get_temp_dir(), 'img') . '.png';
        imagepng(imagecreatetruecolor(100, 100), $tmp);
        Storage::disk('image')->put($key, (string) file_get_contents($tmp));
        $actualSize = filesize($tmp);
        @unlink($tmp);
        Upload::create(['key' => $key, 'type' => 'banner', 'mime' => 'image/jpeg', 'size' => $actualSize, 'status' => 'pending']);

        $first = $this->postJson('/api/admin/uploads/confirm', ['key' => $key]);
        $first->assertStatus(422);
        $this->assertDatabaseHas('uploads', ['key' => $key, 'status' => 'failed']);
        // pending 对象保留（未删）
        $this->assertTrue(Storage::disk('image')->exists($key));

        // 重试：稳定返回 422（而非 409）
        $retry = $this->postJson('/api/admin/uploads/confirm', ['key' => $key]);
        $retry->assertStatus(422);
        $retry->assertJsonPath('message', $first->json('message'));
    }

    public function test_confirm_大小与声明不符_422(): void
    {
        $this->auth();

        // 声明 10 字节，实际写入更大对象
        $key = $this->pendingUpload('image/jpeg', 10);
        Storage::disk('image')->put($key, str_repeat('x', 100));

        $this->postJson('/api/admin/uploads/confirm', ['key' => $key])->assertStatus(422);
        $this->assertDatabaseHas('uploads', ['key' => $key, 'status' => 'failed']);
    }

    public function test_confirm_记录不存在_422(): void
    {
        $this->auth();

        $this->postJson('/api/admin/uploads/confirm', [
            'key' => 'uploads/_pending/banner/' . Str::uuid() . '.jpg',
        ])->assertStatus(422);
    }

    public function test_confirm_finfo真实类型校验触发(): void
    {
        $this->auth();

        $key = 'uploads/_pending/banner/' . Str::uuid() . '.jpg';
        $tmp = tempnam(sys_get_temp_dir(), 'img') . '.png';
        imagepng(imagecreatetruecolor(100, 100), $tmp);
        Storage::disk('image')->put($key, (string) file_get_contents($tmp));
        $actualSize = filesize($tmp);
        @unlink($tmp);
        Upload::create(['key' => $key, 'type' => 'banner', 'mime' => 'image/jpeg', 'size' => $actualSize, 'status' => 'pending']);

        $res = $this->postJson('/api/admin/uploads/confirm', ['key' => $key]);
        $res->assertStatus(422);
        // 关键断言：走到 finfo 分支（而非 size 前置校验）
        $this->assertStringContainsString('类型不符', $res->json('message'));
        $this->assertDatabaseHas('uploads', ['key' => $key, 'status' => 'failed']);
    }

    public function test_抢占写入的processing_at接近当前时间(): void
    {
        $this->auth();

        $key = $this->pendingUpload();
        $upload = Upload::where('key', $key)->first();

        $this->assertTrue(app(UploadService::class)->claimProcessing($upload));

        $record = $upload->refresh();
        $this->assertSame('processing', $record->status);
        // 回归保护：processing_at 必须是"现在"而非 now-5min（Carbon 原地修改陷阱）
        $this->assertTrue($record->processing_at->greaterThanOrEqualTo(now()->subMinute()));
    }

    public function test_新抢占比旧的不可被立即抢占(): void
    {
        $this->auth();

        $key = $this->pendingUpload();
        $upload = Upload::where('key', $key)->first();
        $this->assertTrue(app(UploadService::class)->claimProcessing($upload));

        // 刚抢占（processing_at ≈ now）不可被再次抢占
        $this->assertFalse(app(UploadService::class)->claimProcessing($upload->refresh()));
    }
}
