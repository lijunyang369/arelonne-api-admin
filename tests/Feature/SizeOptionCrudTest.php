<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SizeOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SizeOptionCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 认证:后台路由在 auth:sanctum 之下
        $this->actingAs(User::factory()->create(), 'sanctum');
    }

    /** index:返回迁移种子的 XS/S/M/L/XL,按 sort 升序 */
    public function test_index_returns_seeded_sizes_in_sort_order(): void
    {
        $res = $this->getJson('/api/admin/size-options');

        $res->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame(
            ['XS', 'S', 'M', 'L', 'XL'],
            array_column($res->json('data'), 'name'),
        );
    }

    /** store:创建尺码并落库 */
    public function test_store_creates_size_option(): void
    {
        $res = $this->postJson('/api/admin/size-options', [
            'name' => 'XXL', 'sort' => 6,
        ]);

        $res->assertCreated()->assertJsonPath('data.name', 'XXL');
        $this->assertDatabaseHas('size_options', ['name' => 'XXL', 'sort' => 6]);
    }

    /** store:尺码名重复 → 422 */
    public function test_store_rejects_duplicate_name(): void
    {
        $res = $this->postJson('/api/admin/size-options', [
            'name' => 'XS', 'sort' => 6,
        ]);

        $res->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    /** store:sort 为负 → 422 */
    public function test_store_rejects_negative_sort(): void
    {
        $res = $this->postJson('/api/admin/size-options', [
            'name' => '2XS', 'sort' => -1,
        ]);

        $res->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    /** update:改名成功 */
    public function test_update_renames_size(): void
    {
        $option = SizeOption::where('name', 'M')->firstOrFail();

        $res = $this->putJson("/api/admin/size-options/{$option->id}", [
            'name' => 'M Plus',
        ]);

        $res->assertOk()->assertJsonPath('data.name', 'M Plus');
        $this->assertDatabaseHas('size_options', ['id' => $option->id, 'name' => 'M Plus']);
    }

    /** destroy:未被商品引用的尺码删除成功 */
    public function test_destroy_empty_size_succeeds(): void
    {
        $option = SizeOption::create(['name' => 'XXL', 'sort' => 6]);

        $res = $this->deleteJson("/api/admin/size-options/{$option->id}");

        $res->assertNoContent();
        $this->assertDatabaseMissing('size_options', ['id' => $option->id]);
    }

    /** destroy:已被商品变体使用的尺码 → 422,行保留 */
    public function test_destroy_rejects_size_in_use(): void
    {
        $product = Product::factory()->create();
        ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SIZE-IN-USE-001',
            'color'      => 'Red',
            'size'       => 'XS',
            'price'      => 29.90,
            'stock'      => 1,
        ]);

        $xs = SizeOption::where('name', 'XS')->firstOrFail();

        $res = $this->deleteJson("/api/admin/size-options/{$xs->id}");

        $res->assertUnprocessable();
        $this->assertDatabaseHas('size_options', ['id' => $xs->id]);
    }
}
