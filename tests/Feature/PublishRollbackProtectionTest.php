<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 回滚保护回归用例（原 Task 5 反证验证正式化）：
 * 验证 rollbackPublished 的 protected 跳过逻辑确实承载语义——
 * 制造「DB 已引用 pid abcd1234 对象」的状态（等效一次成功发布后的快照），
 * 重跑时第二张图缺失 → 事务前失败 → 回滚必须跳过 DB 已引用对象。
 * 保护开启：img-01 仍在磁盘；保护关闭：img-01 被删（用例失败）。
 */
class PublishRollbackProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected string $stagingRoot;
    protected string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stagingRoot = storage_path('app/test-staging-' . bin2hex(random_bytes(6)));
        $this->diskRoot = storage_path('app/image-disk-test-' . bin2hex(random_bytes(6)));
        Config::set('image.staging_root', $this->stagingRoot);
        Config::set('filesystems.disks.image', ['driver' => 'local', 'root' => $this->diskRoot, 'throw' => false]);
        Storage::forgetDisk('image');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stagingRoot);
        File::deleteDirectory($this->diskRoot);
        File::deleteDirectory(storage_path('app/publish-locks'));
        parent::tearDown();
    }

    public function test_重跑遇事务前失败_不删DB已引用对象(): void
    {
        $product = Product::factory()->create(['slug' => 'prot-proof']);

        // 制造「DB 已引用 abcd1234」状态（等效成功发布后的快照：SKC + 两张图）
        $skc = $product->skcs()->create([
            'color' => 'Black', 'color_hex' => '#000000',
            'slug' => '1-prot-proof-black', 'status' => 'active',
        ]);
        $product->images()->create([
            'product_skc_id' => $skc->id, 'url' => '/images/products/prot-proof/abcd1234/1-prot-proof-black/img-01.jpg',
            'alt' => 'a', 'sort' => 0, 'is_primary' => true,
        ]);
        $product->images()->create([
            'product_skc_id' => $skc->id, 'url' => '/images/products/prot-proof/abcd1234/1-prot-proof-black/img-02.jpg',
            'alt' => 'b', 'sort' => 1, 'is_primary' => false,
        ]);

        // staging：manifest 两张图，磁盘只有 img-01（img-02 缺失 → 重跑中途事务前失败）
        $dir = "{$this->stagingRoot}/images/products/prot-proof/abcd1234/1-prot-proof-black";
        File::ensureDirectoryExists($dir);
        imagejpeg(imagecreatetruecolor(2000, 1000), "{$dir}/img-01.jpg");
        file_put_contents("{$this->stagingRoot}/images/products/prot-proof/abcd1234/manifest.json", json_encode([
            'product_id' => $product->id,
            'slug'       => 'prot-proof',
            'pid'        => 'abcd1234',
            'skcs'       => [[
                'color'     => 'Black',
                'color_hex' => '#000000',
                'slug'      => '1-prot-proof-black',
                'images'    => [
                    ['file' => 'img-01.jpg', 'alt' => 'a', 'sort' => 0, 'is_primary' => true],
                    ['file' => 'img-02.jpg', 'alt' => 'b', 'sort' => 1, 'is_primary' => false],
                ],
            ]],
        ]));

        // 重跑：img-01 上传后 img-02 缺失 → 中止，回滚本次已写对象
        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertFailed();

        // 保护生效：DB 已引用的 img-01（+变体）不得被回滚删除
        $this->assertFileExists("{$this->diskRoot}/images/products/prot-proof/abcd1234/1-prot-proof-black/img-01.jpg");
        // DB 未动、staging 保留
        $this->assertSame(2, $product->images()->count());
        $this->assertDirectoryExists("{$this->stagingRoot}/images/products/prot-proof/abcd1234");
    }
}
