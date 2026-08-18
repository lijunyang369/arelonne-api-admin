<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * 后台商品列表（含搜索和分页）。
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'images', 'skcs.images']);

        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($categoryId = $request->get('category_id')) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 20));

        // 列表页仅展示主 SKC 的图片（无 primary_skc_id 时保留全部，向后兼容）
        $products->getCollection()->transform(function (Product $product) {
            if ($product->relationLoaded('images') && $product->primary_skc_id) {
                $filtered = $product->images->filter(function (ProductImage $img) use ($product) {
                    return $img->product_skc_id === $product->primary_skc_id
                        || is_null($img->product_skc_id);
                })->values();
                $product->setRelation('images', $filtered);
            }
            return $product;
        });

        return response()->json([
            'data' => ProductResource::collection($products),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
                'last_page'    => $products->lastPage(),
            ],
        ]);
    }

    /**
     * 新增商品。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'slug'        => 'required|string|unique:products,slug',
            'description' => 'nullable|string',
            'category_id' => 'required|exists:categories,id',
            'base_price'  => 'required|numeric|min:0',
            'sale_price'  => 'nullable|numeric|min:0',
            'cost_price'  => 'nullable|numeric|min:0',
            'status'      => 'required|in:draft,active,inactive',
            'sort'        => 'integer',
            'meta'        => 'nullable|array',
        ]);

        // guard 与写入同一事务:行锁在提交前持续持有,防止「建商品 + 建子分类」并发竞态
        $product = DB::transaction(function () use ($data) {
            app(CategoryService::class)->assertAssignableLeaf($data['category_id'] ?? null);
            return Product::create($data);
        });

        return response()->json([
            'data' => new ProductResource($product->load(['category'])),
        ], 201);
    }

    /**
     * 查看单个商品。
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::withTrashed()
            ->with(['category', 'variants', 'images', 'skcs.images'])
            ->findOrFail($id);

        return response()->json([
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * 更新商品。
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::withTrashed()->findOrFail($id);

        $data = $request->validate([
            'name'        => 'string|max:255',
            'slug'        => "string|unique:products,slug,{$id}",
            'description' => 'nullable|string',
            'category_id' => 'exists:categories,id',
            'base_price'  => 'numeric|min:0',
            'sale_price'  => 'nullable|numeric|min:0',
            'cost_price'  => 'nullable|numeric|min:0',
            'status'      => 'in:draft,active,inactive',
            'sort'        => 'integer',
            'meta'        => 'nullable|array',
        ]);

        // guard 与写入同一事务:行锁在提交前持续持有,防止「改挂分类 + 建子分类」并发竞态
        DB::transaction(function () use ($product, $data) {
            app(CategoryService::class)->assertAssignableLeaf(
                $data['category_id'] ?? null,
                $product->category_id,
            );
            $product->update($data);
        });

        // SKC/图片快照替换（仅当请求携带 skcs 时；上传组件/手工填写产生的 URL 直接落库）
        if ($request->has('skcs')) {
            $validated = $request->validate([
                'skcs'                       => ['array'],
                'skcs.*.color'               => ['required', 'string'],
                'skcs.*.color_hex'           => ['nullable', 'string'],
                'skcs.*.slug'                => ['required', 'string'],
                'skcs.*.images'              => ['array'],
                'skcs.*.images.*.url'        => ['required', 'string'],
                'skcs.*.images.*.alt'        => ['nullable', 'string'],
                'skcs.*.images.*.sort'       => ['nullable', 'integer'],
                'skcs.*.images.*.is_primary' => ['nullable', 'boolean'],
            ]);

            app(\App\Services\ProductSkuImageService::class)->replaceFromForm($product, $validated['skcs']);
        }

        return response()->json([
            'data' => new ProductResource($product->load(['category'])),
        ]);
    }

    /**
     * 软删除商品。
     */
    public function destroy(int $id): JsonResponse
    {
        $product = Product::withTrashed()->findOrFail($id);
        $product->delete();

        return response()->json(null, 204);
    }

    /**
     * 批量导入商品。
     */
    public function batchImport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'products' => 'required|array|min:1',
            'products.*.name'        => 'required|string|max:255',
            'products.*.slug'        => 'required|string',
            'products.*.description' => 'nullable|string',
            'products.*.category_id' => 'required|exists:categories,id',
            'products.*.base_price'  => 'required|numeric|min:0',
            'products.*.sale_price'  => 'nullable|numeric|min:0',
            'products.*.status'      => 'required|in:draft,active,inactive',
        ]);

        $created = [];
        foreach ($data['products'] as $item) {
            // 每个 item 独立事务:guard 与写入同事务,行锁防并发竞态
            $product = DB::transaction(function () use ($item) {
                app(CategoryService::class)->assertAssignableLeaf($item['category_id'] ?? null);
                return Product::create($item);
            });
            $created[] = $product->id;
        }

        return response()->json([
            'message'   => 'Import completed.',
            'count'     => count($created),
            'product_ids' => $created,
        ], 201);
    }
}
