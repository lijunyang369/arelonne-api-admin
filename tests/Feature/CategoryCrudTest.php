<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 认证:后台路由在 auth:sanctum 之下
        $this->actingAs(User::factory()->create(), 'sanctum');
    }

    /** 创建根分类:slug 自动按 name 生成 kebab-case */
    public function test_store_generates_slug_from_name(): void
    {
        $res = $this->postJson('/api/admin/categories', [
            'name' => 'New Tops', 'sort' => 1, 'status' => 'active',
        ]);

        $res->assertCreated()->assertJsonPath('data.slug', 'new-tops');
        $this->assertDatabaseHas('categories', ['name' => 'New Tops', 'slug' => 'new-tops']);
    }

    /** 保留 slug 禁止使用 */
    public function test_store_rejects_reserved_slug(): void
    {
        foreach (['all', 'bras', 'linen', 'cotton-linen', 'bras-innerwear'] as $slug) {
            $res = $this->postJson('/api/admin/categories', [
                'name' => 'X', 'slug' => $slug, 'status' => 'active',
            ]);
            $res->assertUnprocessable()->assertJsonValidationErrors('slug');
        }
    }

    /** 两级不变量:子分类的 parent 必须是根 */
    public function test_store_rejects_parent_that_is_not_root(): void
    {
        $root = Category::factory()->create(['parent_id' => null]);
        $child = Category::factory()->create(['parent_id' => $root->id]);

        $res = $this->postJson('/api/admin/categories', [
            'name' => 'Grandchild', 'parent_id' => $child->id, 'status' => 'active',
        ]);
        $res->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    /** 两级不变量:更新时禁止自环 */
    public function test_update_rejects_self_as_parent(): void
    {
        $root = Category::factory()->create(['parent_id' => null]);
        $res = $this->putJson("/api/admin/categories/{$root->id}", ['parent_id' => $root->id]);
        $res->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    /** 两级不变量:有子分类的分类不能降级为子 */
    public function test_update_rejects_demotion_of_category_with_children(): void
    {
        $rootA = Category::factory()->create(['parent_id' => null]);
        $rootB = Category::factory()->create(['parent_id' => null]);
        Category::factory()->create(['parent_id' => $rootA->id]);

        $res = $this->putJson("/api/admin/categories/{$rootA->id}", ['parent_id' => $rootB->id]);
        $res->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    /** 两级不变量:有商品的叶子不能成为父分类 */
    public function test_store_rejects_child_under_leaf_with_products(): void
    {
        $leaf = Category::factory()->create(['parent_id' => null]);
        Product::factory()->create(['category_id' => $leaf->id]);

        $res = $this->postJson('/api/admin/categories', [
            'name' => 'Child', 'parent_id' => $leaf->id, 'status' => 'active',
        ]);
        $res->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    /** slug 创建后锁定:更新提交 slug → 422 */
    public function test_update_rejects_slug_change(): void
    {
        $root = Category::factory()->create(['slug' => 'locked-slug']);
        $res = $this->putJson("/api/admin/categories/{$root->id}", ['slug' => 'hacked']);
        $res->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    /** 删除保护:有商品(含软删)拒绝 */
    public function test_destroy_rejects_category_with_products_including_trashed(): void
    {
        $leaf = Category::factory()->create(['parent_id' => null]);
        $product = Product::factory()->create(['category_id' => $leaf->id]);
        $product->delete(); // 软删,仍应阻止

        $res = $this->deleteJson("/api/admin/categories/{$leaf->id}");
        $res->assertUnprocessable();
        $this->assertDatabaseHas('categories', ['id' => $leaf->id]);
    }

    /** 删除保护:有子分类拒绝 */
    public function test_destroy_rejects_category_with_children(): void
    {
        $root = Category::factory()->create(['parent_id' => null]);
        Category::factory()->create(['parent_id' => $root->id]);

        $res = $this->deleteJson("/api/admin/categories/{$root->id}");
        $res->assertUnprocessable();
        $this->assertDatabaseHas('categories', ['id' => $root->id]);
    }

    /** 空分类删除成功 */
    public function test_destroy_empty_category_succeeds(): void
    {
        $empty = Category::factory()->create(['parent_id' => null]);
        $res = $this->deleteJson("/api/admin/categories/{$empty->id}");
        $res->assertNoContent();
        $this->assertDatabaseMissing('categories', ['id' => $empty->id]);
    }

    /** sort 不允许负数 */
    public function test_store_rejects_negative_sort(): void
    {
        $res = $this->postJson('/api/admin/categories', [
            'name' => 'X', 'sort' => -1, 'status' => 'active',
        ]);
        $res->assertUnprocessable()->assertJsonValidationErrors('sort');
    }
}
