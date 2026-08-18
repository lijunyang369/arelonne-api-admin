<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryFkTest extends TestCase
{
    use RefreshDatabase;

    /** 直接删除有关联商品的分类必须被数据库拒绝(RESTRICT) */
    public function test_category_with_products_cannot_be_deleted_at_db_level(): void
    {
        $category = Category::create([
            'name' => 'FK Test', 'slug' => 'fk-test', 'sort' => 0, 'status' => 'active',
        ]);
        Product::create([
            'name' => 'FK Product', 'slug' => 'fk-product', 'category_id' => $category->id,
            'base_price' => 10, 'status' => 'active', 'sort' => 0, 'meta' => [],
        ]);

        $this->expectException(QueryException::class);
        $category->delete();
    }

    /** 直接删除有子分类的分类必须被数据库拒绝(RESTRICT) */
    public function test_category_with_children_cannot_be_deleted_at_db_level(): void
    {
        $parent = Category::create([
            'name' => 'FK Parent', 'slug' => 'fk-parent', 'sort' => 0, 'status' => 'active',
        ]);
        Category::create([
            'name' => 'FK Child', 'slug' => 'fk-child', 'parent_id' => $parent->id,
            'sort' => 0, 'status' => 'active',
        ]);

        $this->expectException(QueryException::class);
        $parent->delete();
    }
}
