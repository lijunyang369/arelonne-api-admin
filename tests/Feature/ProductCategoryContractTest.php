<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategoryContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(\App\Models\User::factory()->create(), 'sanctum');
    }

    /** 新建商品挂停用分类 → 422 */
    public function test_create_rejects_inactive_category(): void
    {
        $inactive = Category::factory()->create(['status' => 'inactive', 'parent_id' => null]);
        $res = $this->postJson('/api/admin/products', [
            'name' => 'X', 'slug' => 'x-product', 'category_id' => $inactive->id,
            'base_price' => 10, 'status' => 'active',
        ]);
        $res->assertUnprocessable();
    }

    /** 新建商品挂父分类(非叶子)→ 422 */
    public function test_create_rejects_non_leaf_category(): void
    {
        $root = Category::factory()->create(['parent_id' => null]);
        Category::factory()->create(['parent_id' => $root->id]);
        $res = $this->postJson('/api/admin/products', [
            'name' => 'X', 'slug' => 'x-product', 'category_id' => $root->id,
            'base_price' => 10, 'status' => 'active',
        ]);
        $res->assertUnprocessable();
    }

    /** 更新商品但分类未变(即使已停用)→ 允许 */
    public function test_update_allows_unchanged_inactive_category(): void
    {
        $leaf = Category::factory()->create(['status' => 'active', 'parent_id' => null]);
        $product = Product::factory()->create(['category_id' => $leaf->id]);
        $leaf->update(['status' => 'inactive']);

        $res = $this->putJson("/api/admin/products/{$product->id}", [
            'name' => 'Renamed', 'category_id' => $leaf->id,
        ]);
        $res->assertOk();
    }

    /** 更新商品改挂停用分类 → 422 */
    public function test_update_rejects_change_to_inactive_category(): void
    {
        $active = Category::factory()->create(['status' => 'active', 'parent_id' => null]);
        $inactive = Category::factory()->create(['status' => 'inactive', 'parent_id' => null]);
        $product = Product::factory()->create(['category_id' => $active->id]);

        $res = $this->putJson("/api/admin/products/{$product->id}", [
            'category_id' => $inactive->id,
        ]);
        $res->assertUnprocessable();
    }
}
