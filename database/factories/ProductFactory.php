<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 商品测试数据（category_id 非空，必须挂 Category 工厂）。
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name'        => fake()->words(3, true),
            'slug'        => fake()->unique()->slug(3),
            'status'      => 'active',
            'base_price'  => 29.90,
            'sale_price'  => null,
        ];
    }
}
