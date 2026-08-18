<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishImagesCommandTest extends TestCase
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

    /** 构造一个带一张图的暂存商品 + manifest */
    private function makeStagedProduct(Product $product, string $pid = 'abcd1234'): array
    {
        $slug = $product->slug;
        $skcSlug = "{$product->id}-{$slug}-black";
        $dir = "{$this->stagingRoot}/images/products/{$slug}/{$pid}/{$skcSlug}";
        File::ensureDirectoryExists($dir);
        imagejpeg(imagecreatetruecolor(2000, 1000), "{$dir}/img-01.jpg");

        $manifest = [
            'product_id' => $product->id,
            'slug'       => $slug,
            'pid'        => $pid,
            'skcs'       => [[
                'color'     => 'Black',
                'color_hex' => '#000000',
                'slug'      => $skcSlug,
                'images'    => [['file' => 'img-01.jpg', 'alt' => '测试', 'sort' => 0, 'is_primary' => true]],
            ]],
        ];
        file_put_contents("{$this->stagingRoot}/images/products/{$slug}/{$pid}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));

        return ['manifest' => $manifest, 'dir' => $dir];
    }

    public function test_发布成功_对象先于DB_变体恒生成_清理暂存(): void
    {
        $product = Product::factory()->create(['slug' => 'publish-test']);
        $this->makeStagedProduct($product);

        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertSuccessful();

        // 对象：原图 + 恒三档
        $base = "{$this->diskRoot}/images/products/publish-test/abcd1234/1-publish-test-black";
        $this->assertFileExists("{$base}/img-01.jpg");
        $this->assertFileExists("{$base}/img-01_480.webp");
        $this->assertFileExists("{$base}/img-01_960.webp");
        $this->assertFileExists("{$base}/img-01_1600.webp");

        // DB：url 含 pid，product_id 已填
        $img = $product->images()->first();
        $this->assertNotNull($img);
        $this->assertSame('/images/products/publish-test/abcd1234/1-publish-test-black/img-01.jpg', $img->url);
        $this->assertSame($product->id, $img->product_id);
        // 命令通过自己的实例写 DB，测试实例需 refresh 才能读到 primary_skc_id
        $product->refresh();
        $this->assertSame($product->skcs()->first()->id, $product->primary_skc_id);

        // 暂存已清
        $this->assertDirectoryDoesNotExist("{$this->stagingRoot}/images/products/publish-test/abcd1234");
    }

    public function test_重发布_回收旧pid对象_并删除未覆盖的旧SKC(): void
    {
        $product = Product::factory()->create(['slug' => 'repub-test']);

        // 第一次发布 pid=oldpid01，含 Black
        $this->makeStagedProduct($product, 'oldpid01');
        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertSuccessful();
        $this->assertFileExists("{$this->diskRoot}/images/products/repub-test/oldpid01/1-repub-test-black/img-01.jpg");

        // 第二次发布 pid=newpid02：manifest 只含 White（Black 不在快照中）
        $slug = $product->slug;
        $skcSlug = "{$product->id}-{$slug}-white";
        $dir = "{$this->stagingRoot}/images/products/{$slug}/newpid02/{$skcSlug}";
        File::ensureDirectoryExists($dir);
        imagejpeg(imagecreatetruecolor(2000, 1000), "{$dir}/img-01.jpg");
        $manifest = [
            'product_id' => $product->id,
            'slug'       => $slug,
            'pid'        => 'newpid02',
            'skcs'       => [[
                'color'     => 'White',
                'color_hex' => '#ffffff',
                'slug'      => $skcSlug,
                'images'    => [['file' => 'img-01.jpg', 'alt' => 'x', 'sort' => 0, 'is_primary' => true]],
            ]],
        ];
        file_put_contents("{$this->stagingRoot}/images/products/{$slug}/newpid02/manifest.json", json_encode($manifest));

        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertSuccessful();

        // 新 pid 存在，旧 pid 已回收
        $this->assertFileExists("{$this->diskRoot}/images/products/repub-test/newpid02/{$skcSlug}/img-01.jpg");
        $this->assertDirectoryDoesNotExist("{$this->diskRoot}/images/products/repub-test/oldpid01");

        // DB：旧色 SKC 已删，只剩 White
        $this->assertSame(0, $product->skcs()->where('color', 'Black')->count());
        $this->assertSame(1, $product->skcs()->where('color', 'White')->count());
        $this->assertStringContainsString('/newpid02/', $product->images()->first()->url);
    }

    public function test_坏图整图拒绝_其余图正常发布(): void
    {
        $product = Product::factory()->create(['slug' => 'badimg-test']);
        $this->makeStagedProduct($product);

        // 追加一张损坏图到 manifest 与磁盘
        $manifestPath = "{$this->stagingRoot}/images/products/badimg-test/abcd1234/manifest.json";
        $manifest = json_decode(File::get($manifestPath), true);
        $manifest['skcs'][0]['images'][] = ['file' => 'img-02.jpg', 'alt' => '', 'sort' => 1, 'is_primary' => false];
        file_put_contents($manifestPath, json_encode($manifest));
        file_put_contents("{$this->stagingRoot}/images/products/badimg-test/abcd1234/1-badimg-test-black/img-02.jpg", 'corrupted');

        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertSuccessful();

        // 坏图未发布、未入库
        $this->assertFileDoesNotExist("{$this->diskRoot}/images/products/badimg-test/abcd1234/1-badimg-test-black/img-02.jpg");
        $this->assertSame(1, $product->images()->count());
    }

    public function test_变体生成失败_整图拒绝_原图回滚(): void
    {
        $product = Product::factory()->create(['slug' => 'variant-fail']);

        // 5000x5000 = 2500 万像素 > max_pixels(2400 万) → 变体 generate 返回 false
        $slug = $product->slug;
        $skcSlug = "{$product->id}-{$slug}-black";
        $dir = "{$this->stagingRoot}/images/products/{$slug}/abcd1234/{$skcSlug}";
        File::ensureDirectoryExists($dir);
        imagejpeg(imagecreatetruecolor(5000, 5000), "{$dir}/img-01.jpg");
        file_put_contents("{$this->stagingRoot}/images/products/{$slug}/abcd1234/manifest.json", json_encode([
            'product_id' => $product->id,
            'slug'       => $slug,
            'pid'        => 'abcd1234',
            'skcs'       => [[
                'color'     => 'Black',
                'color_hex' => '#000000',
                'slug'      => $skcSlug,
                'images'    => [['file' => 'img-01.jpg', 'alt' => 'x', 'sort' => 0, 'is_primary' => true]],
            ]],
        ]));

        // 全图被拒 → 失败：不发布、不更新 DB、保留 staging
        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertFailed();

        // 原图已回滚（不在磁盘）、DB 无图片、staging 保留
        $this->assertFileDoesNotExist("{$this->diskRoot}/images/products/variant-fail/abcd1234/{$skcSlug}/img-01.jpg");
        $this->assertSame(0, $product->images()->count());
        $this->assertDirectoryExists("{$this->stagingRoot}/images/products/variant-fail/abcd1234");
    }

    public function test_文件缺失_中止且保留暂存_不更新DB(): void
    {
        $product = Product::factory()->create(['slug' => 'fail-test']);
        $this->makeStagedProduct($product);

        // 删除磁盘文件但保留 manifest（模拟中途损坏）
        File::delete("{$this->stagingRoot}/images/products/fail-test/abcd1234/1-fail-test-black/img-01.jpg");

        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertFailed();

        // DB 未更新、staging 保留、磁盘无对象
        $this->assertSame(0, $product->images()->count());
        $this->assertDirectoryExists("{$this->stagingRoot}/images/products/fail-test/abcd1234");
        $this->assertDirectoryDoesNotExist("{$this->diskRoot}/images/products/fail-test");
    }

    public function test_slug漂移_拒绝发布(): void
    {
        $product = Product::factory()->create(['slug' => 'slug-drift']);
        $this->makeStagedProduct($product);

        // 修改 DB 商品 slug（模拟 sync 后被改名）
        $product->update(['slug' => 'renamed']);

        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertFailed();

        // 未发布任何对象、staging 保留
        $this->assertDirectoryDoesNotExist("{$this->diskRoot}/images/products/slug-drift");
        $this->assertDirectoryExists("{$this->stagingRoot}/images/products/slug-drift/abcd1234");
    }

    public function test_删除颜色后重新发布同色_恢复软删SKC不撞唯一索引(): void
    {
        $product = Product::factory()->create(['slug' => 'skc-restore']);
        $slug = $product->slug;

        // 第一次发布 pid1：Black
        $this->makeStagedProduct($product, 'pid00001');
        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertSuccessful();

        // 第二次发布 pid2：只含 White → 4.2 把未覆盖的 Black 软删
        $skcSlugWhite = "{$product->id}-{$slug}-white";
        $dir2 = "{$this->stagingRoot}/images/products/{$slug}/pid00002/{$skcSlugWhite}";
        File::ensureDirectoryExists($dir2);
        imagejpeg(imagecreatetruecolor(2000, 1000), "{$dir2}/img-01.jpg");
        file_put_contents("{$this->stagingRoot}/images/products/{$slug}/pid00002/manifest.json", json_encode([
            'product_id' => $product->id,
            'slug'       => $slug,
            'pid'        => 'pid00002',
            'skcs'       => [[
                'color'     => 'White',
                'color_hex' => '#ffffff',
                'slug'      => $skcSlugWhite,
                'images'    => [['file' => 'img-01.jpg', 'alt' => 'x', 'sort' => 0, 'is_primary' => true]],
            ]],
        ]));
        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertSuccessful();

        $black = $product->skcs()->withTrashed()->where('color', 'Black')->first();
        $this->assertNotNull($black);
        $this->assertNotNull($black->deleted_at); // 已软删

        // 第三次发布 pid3：Black 重新加入 → withTrashed updateOrCreate 恢复软删行，不撞 (product_id,color) 唯一索引
        $skcSlugBlack = "{$product->id}-{$slug}-black";
        $dir3 = "{$this->stagingRoot}/images/products/{$slug}/pid00003/{$skcSlugBlack}";
        File::ensureDirectoryExists($dir3);
        imagejpeg(imagecreatetruecolor(2000, 1000), "{$dir3}/img-01.jpg");
        file_put_contents("{$this->stagingRoot}/images/products/{$slug}/pid00003/manifest.json", json_encode([
            'product_id' => $product->id,
            'slug'       => $slug,
            'pid'        => 'pid00003',
            'skcs'       => [[
                'color'     => 'Black',
                'color_hex' => '#000000',
                'slug'      => $skcSlugBlack,
                'images'    => [['file' => 'img-01.jpg', 'alt' => 'x', 'sort' => 0, 'is_primary' => true]],
            ]],
        ]));
        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertSuccessful();

        // Black SKC 已恢复（deleted_at 为 null），DB 中 Black 仅一行
        $black->refresh();
        $this->assertNull($black->deleted_at);
        $this->assertSame('active', $black->status);
        $this->assertSame(1, $product->skcs()->withTrashed()->where('color', 'Black')->count());
    }

    public function test_slug变更后重发布_旧slug前缀被回收(): void
    {
        $product = Product::factory()->create(['slug' => 'slug-old']);
        $this->makeStagedProduct($product, 'pid00001');
        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertSuccessful();
        $this->assertFileExists("{$this->diskRoot}/images/products/slug-old/pid00001/1-slug-old-black/img-01.jpg");

        // 商品改名 + 重新 sync（staging 用新 slug + 新 pid）
        $product->update(['slug' => 'slug-new']);
        $slug = $product->slug;
        $skcSlug = "{$product->id}-{$slug}-black";
        $dir = "{$this->stagingRoot}/images/products/{$slug}/pid00002/{$skcSlug}";
        File::ensureDirectoryExists($dir);
        imagejpeg(imagecreatetruecolor(2000, 1000), "{$dir}/img-01.jpg");
        file_put_contents("{$this->stagingRoot}/images/products/{$slug}/pid00002/manifest.json", json_encode([
            'product_id' => $product->id,
            'slug'       => $slug,
            'pid'        => 'pid00002',
            'skcs'       => [[
                'color'     => 'Black',
                'color_hex' => '#000000',
                'slug'      => $skcSlug,
                'images'    => [['file' => 'img-01.jpg', 'alt' => 'x', 'sort' => 0, 'is_primary' => true]],
            ]],
        ]));

        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertSuccessful();

        // 新 slug+pid 存在，旧 slug 前缀下的旧 pid 目录被回收（按旧 URL 的 slug，而非当前 slug）
        $this->assertFileExists("{$this->diskRoot}/images/products/slug-new/pid00002/{$skcSlug}/img-01.jpg");
        $this->assertDirectoryDoesNotExist("{$this->diskRoot}/images/products/slug-old/pid00001");
    }

    public function test_多manifest_报错并跳过(): void
    {
        $product = Product::factory()->create(['slug' => 'multi-test']);
        $this->makeStagedProduct($product, 'pid00001');
        $this->makeStagedProduct($product, 'pid00002');

        $this->artisan('sync:publish-images', ['productId' => $product->id])->assertFailed();
        $this->assertSame(0, $product->images()->count());

        // --pid 精确指定可成功
        $this->artisan('sync:publish-images', ['productId' => $product->id, '--pid' => 'pid00002'])->assertSuccessful();
        $this->assertStringContainsString('/pid00002/', $product->images()->first()->url);
    }
}
