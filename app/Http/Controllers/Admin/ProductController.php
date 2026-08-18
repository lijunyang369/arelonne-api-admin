<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * 后台商品列表（含搜索和分页）。
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'images']);

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

        $product = Product::create($data);

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
            ->with(['category', 'variants', 'images'])
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

        $product->update($data);

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
            $product = Product::create($item);
            $created[] = $product->id;
        }

        return response()->json([
            'message'   => 'Import completed.',
            'count'     => count($created),
            'product_ids' => $created,
        ], 201);
    }
}
