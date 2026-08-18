<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 分类测试数据。
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'name'   => fake()->unique()->words(2, true),
            'slug'   => fake()->unique()->slug(2),
            'status' => 'active',
            'sort'   => 0,
        ];
    }
}
