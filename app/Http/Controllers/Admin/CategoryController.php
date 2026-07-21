<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * 获取分类树（含父子关系）。
     */
    public function index(): JsonResponse
    {
        $categories = Category::where('status', 'active')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        // 构建树形结构：parent_id=null 为根
        $tree = $categories->whereNull('parent_id')->map(function (Category $cat) use ($categories) {
            return [
                'id'       => $cat->id,
                'name'     => $cat->name,
                'slug'     => $cat->slug,
                'children' => $categories->where('parent_id', $cat->id)->map(fn (Category $child) => [
                    'id'   => $child->id,
                    'name' => $child->name,
                    'slug' => $child->slug,
                ])->values()->toArray(),
            ];
        })->values()->toArray();

        return response()->json(['data' => $tree]);
    }
}
