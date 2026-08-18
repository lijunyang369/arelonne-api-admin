<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Upload;
use App\Support\ImageVariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diskRoot = storage_path('app/image-disk-test-' . bin2hex(random_bytes(6)));
        Config::set('filesystems.disks.image', ['driver' => 'local', 'root' => $this->diskRoot, 'throw' => false]);
        Storage::forgetDisk('image');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    public function test_白名单同步命令_只同步白名单文件(): void
    {
        $fakePublic = storage_path('app/fake-public-' . bin2hex(random_bytes(6)));
        File::ensureDirectoryExists("{$fakePublic}/brand");
        File::ensureDirectoryExists("{$fakePublic}/images/products/fake-slug");
        file_put_contents("{$fakePublic}/brand/logo.svg", '<svg></svg>');
        file_put_contents("{$fakePublic}/images/products/fake-slug/x.jpg", 'fake');

        $manifest = storage_path('app/fake-manifest-' . bin2hex(random_bytes(6)) . '.json');
        file_put_contents($manifest, json_encode(['entries' => ['brand']]));

        $this->artisan('storage:sync-public-to-s3', [
            'publicRoot' => $fakePublic,
            '--manifest' => $manifest,
        ])->assertSuccessful();

        $this->assertSame('<svg></svg>', File::get("{$this->diskRoot}/brand/logo.svg"));
        // 不在白名单的 images/products 不会被同步
        $this->assertFileDoesNotExist("{$this->diskRoot}/images/products/fake-slug/x.jpg");

        File::deleteDirectory($fakePublic);
        @unlink($manifest);
    }

    public function test_manifest含products_拒绝同步且命令失败(): void
    {
        $fakePublic = storage_path('app/fake-public-' . bin2hex(random_bytes(6)));
        File::ensureDirectoryExists("{$fakePublic}/images/products/fake-slug");
        file_put_contents("{$fakePublic}/images/products/fake-slug/x.jpg", 'fake');

        $manifest = storage_path('app/fake-manifest-' . bin2hex(random_bytes(6)) . '.json');
        file_put_contents($manifest, json_encode(['entries' => ['images/products']]));

        // fail-closed：含管线独占前缀 → 拒绝 + FAILURE
        $this->artisan('storage:sync-public-to-s3', [
            'publicRoot' => $fakePublic,
            '--manifest' => $manifest,
        ])->assertFailed();

        $this->assertFileDoesNotExist("{$this->diskRoot}/images/products/fake-slug/x.jpg");

        File::deleteDirectory($fakePublic);
        @unlink($manifest);
    }

    public function test_manifest缺失_命令失败(): void
    {
        $this->artisan('storage:sync-public-to-s3', [
            'publicRoot' => storage_path('app/whatever'),
            '--manifest' => storage_path('app/not-exists-' . bin2hex(random_bytes(6)) . '.json'),
        ])->assertFailed();
    }

    public function test_verify_商品图缺失任一变体即失败(): void
    {
        $product = Product::factory()->create(['slug' => 'verify-test']);
        $url = '/images/products/verify-test/abcd1234/skc/img.jpg';

        // 只放原图和 _480，缺 _960 → 失败
        File::ensureDirectoryExists(dirname("{$this->diskRoot}{$url}"));
        imagejpeg(imagecreatetruecolor(2000, 1000), "{$this->diskRoot}{$url}");
        imagejpeg(imagecreatetruecolor(480, 240), $this->diskRoot . ImageVariants::variantPath($url, 480));

        $product->images()->create([
            'product_skc_id' => null,
            'url'            => $url,
            'alt'            => 'x',
            'sort'           => 0,
            'is_primary'     => true,
        ]);

        $this->artisan('storage:verify')->assertFailed();
    }

    public function test_verify_全部存在时成功(): void
    {
        $product = Product::factory()->create(['slug' => 'verify-ok']);
        $url = '/images/products/verify-ok/abcd1234/skc/img.jpg';

        File::ensureDirectoryExists(dirname("{$this->diskRoot}{$url}"));
        imagejpeg(imagecreatetruecolor(2000, 1000), "{$this->diskRoot}{$url}");
        foreach ([480, 960, 1600] as $w) {
            imagejpeg(imagecreatetruecolor($w, (int) ($w / 2)), $this->diskRoot . ImageVariants::variantPath($url, $w));
        }

        $product->images()->create([
            'product_skc_id' => null,
            'url'            => $url,
            'alt'            => 'x',
            'sort'           => 0,
            'is_primary'     => true,
        ]);

        $this->artisan('storage:verify')->assertSuccessful();
    }

    public function test_verify_检查已确认上传(): void
    {
        $final = 'uploads/banner/20260818/test.jpg';
        Upload::create([
            'key'        => 'uploads/_pending/banner/old.jpg',
            'type'       => 'banner',
            'mime'       => 'image/jpeg',
            'size'       => 10,
            'status'     => 'confirmed',
            'final_path' => "/{$final}",
            'thumb_path' => '/' . ImageVariants::variantPath($final, 480),
            'width'      => 100,
            'height'     => 100,
        ]);

        $this->artisan('storage:verify')->assertFailed();
    }
}
