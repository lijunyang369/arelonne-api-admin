<?php

namespace Tests\Unit;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactorySmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_商品工厂可创建并自动挂分类(): void
    {
        $product = Product::factory()->create();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertNotNull($product->category_id);
        $this->assertDatabaseHas('categories', ['id' => $product->category_id]);
    }
}
