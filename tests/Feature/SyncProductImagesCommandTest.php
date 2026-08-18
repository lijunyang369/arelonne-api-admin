<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncProductImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $stagingRoot;

    protected function setUp(): void
    {
        parent::setUp();
        // 隔离：staging 根指向唯一临时目录（绝不碰真实 staging）
        $this->stagingRoot = storage_path('app/test-staging-' . bin2hex(random_bytes(6)));
        Config::set('image.staging_root', $this->stagingRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stagingRoot);
        parent::tearDown();
    }

    public function test_同步命令_下载图片到暂存区_写manifest_不写数据库(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
        imagejpeg(imagecreatetruecolor(800, 800), $tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        Http::preventStrayRequests();
        Http::fake([
            'oglmove.com/*' => Http::response([
                'product' => [
                    'title'    => '测试商品',
                    'variants' => [['id' => 1001, 'option1' => 'Black']],
                    'images'   => [[
                        'id'          => 2001,
                        'src'         => 'https://cdn.shopify.com/test-black.jpg',
                        'alt'         => '测试图',
                        'variant_ids' => [1001],
                    ]],
                ],
            ], 200),
            'cdn.shopify.com/*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $product = Product::factory()->create(['slug' => 'test-product']);

        $this->artisan('sync:product-images', [
            'url'       => 'https://oglmove.com/products/test-product',
            'productId' => $product->id,
        ])->assertSuccessful();

        // 不写 DB：图片记录为空
        $this->assertSame(0, $product->images()->count());
        $this->assertSame(0, $product->skcs()->count());

        // 暂存区结构：images/products/<slug>/<pid>/<skc-slug>/<file> + manifest.json
        $productDir = glob("{$this->stagingRoot}/images/products/test-product/*");
        $this->assertCount(1, $productDir);
        $pid = basename($productDir[0]);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $pid);

        $manifestPath = "{$productDir[0]}/manifest.json";
        $this->assertFileExists($manifestPath);
        $manifest = json_decode(File::get($manifestPath), true);
        $this->assertSame($product->id, $manifest['product_id']);
        $this->assertSame($pid, $manifest['pid']);
        $this->assertCount(1, $manifest['skcs']);
        $this->assertSame('Black', $manifest['skcs'][0]['color']);
        $this->assertSame('1-test-product-black-01.jpg', $manifest['skcs'][0]['images'][0]['file']);
        $this->assertFileExists("{$productDir[0]}/1-test-product-black/1-test-product-black-01.jpg");

        Http::assertSent(fn ($req) => str_contains($req->url(), 'oglmove.com/products/test-product.json'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'cdn.shopify.com/test-black.jpg'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'sync-store.test'));
    }
}
