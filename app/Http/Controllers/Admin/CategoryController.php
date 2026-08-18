<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /** 分类服务(创建/更新/删除的业务规则) */
    public function __construct(private CategoryService $service) {}

    /**
     * 分类列表:默认 active 树(兼容商品表单选择器);
     * status=all 返回全部(管理页用,含 inactive)。
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => 'sometimes|in:active,all']);

        $query = Category::query()->orderBy('sort')->orderBy('id');
        if ($request->get('status', 'active') === 'all') {
            $query->with('children');
        } else {
            // active 模式:根与子分类都过滤,避免 inactive 子分类漏入商品表单选择器
            $query->where('status', 'active')
                ->with(['children' => fn ($q) => $q->where('status', 'active')]);
        }

        // 树形:parent_id=null 为根
        $tree = $query->whereNull('parent_id')->get()
            ->map(fn (Category $cat) => new CategoryResource($cat));

        return response()->json(['data' => $tree]);
    }

    /**
     * 创建分类。
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->service->create($request->validated());
        return response()->json(['data' => new CategoryResource($category)], 201);
    }

    /**
     * 更新分类。
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category = $this->service->update($category, $request->validated());
        return response()->json(['data' => new CategoryResource($category)]);
    }

    /**
     * 删除分类(依赖检查通过后物理删除)。
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->service->destroy($category);
        return response()->json(null, 204);
    }
}
