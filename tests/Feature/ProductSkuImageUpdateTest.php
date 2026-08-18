<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductSkuImageUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_更新商品_快照替换SKC与图片(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $product = Product::factory()->create();

        $this->putJson('/api/admin/products/' . $product->id, [
            'name' => '改名后的商品',
            'category_id' => $product->category_id,
            'skcs' => [
                ['color' => 'Black', 'color_hex' => '#000000', 'slug' => 'p-black', 'images' => [
                    ['url' => '/images/products/p/pid/black/a.jpg', 'alt' => 'a', 'sort' => 0, 'is_primary' => true],
                    ['url' => '/images/products/p/pid/black/b.jpg', 'alt' => null, 'sort' => 1, 'is_primary' => false],
                ]],
            ],
        ])->assertSuccessful();

        $this->assertSame('改名后的商品', $product->refresh()->name);
        $this->assertSame(2, $product->images()->count());
        $this->assertSame(1, $product->skcs()->where('color', 'Black')->count());
    }

    public function test_删除颜色后重新加入_恢复软删SKC不撞唯一索引(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $product = Product::factory()->create();

        // 第一次：Black
        $this->putJson('/api/admin/products/' . $product->id, [
            'category_id' => $product->category_id,
            'skcs' => [['color' => 'Black', 'slug' => 'p-black', 'images' => []]],
        ])->assertSuccessful();

        // 第二次：White（Black 被软删）
        $this->putJson('/api/admin/products/' . $product->id, [
            'category_id' => $product->category_id,
            'skcs' => [['color' => 'White', 'slug' => 'p-white', 'images' => []]],
        ])->assertSuccessful();

        // 第三次：Black 重新加入 → 必须恢复软删行而非 insert 撞唯一索引
        $this->putJson('/api/admin/products/' . $product->id, [
            'category_id' => $product->category_id,
            'skcs' => [['color' => 'Black', 'slug' => 'p-black', 'images' => []]],
        ])->assertSuccessful();

        $black = $product->skcs()->where('color', 'Black')->first();
        $this->assertNotNull($black);
        $this->assertNull($black->deleted_at);
    }
}
