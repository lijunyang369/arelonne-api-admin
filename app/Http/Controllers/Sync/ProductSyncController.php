<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductSyncController extends Controller
{
    /**
     * 接收 🇨🇳 Admin 推送的商品数据，在 🇺🇸 Store 侧 upsert。
     *
     * 只同步前台展示需要的字段（不含 cost_price 等内部数据）。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'          => 'required|integer',
            'name'        => 'required|string',
            'slug'        => 'required|string',
            'description' => 'nullable|string',
            'base_price'  => 'required|numeric',
            'sale_price'  => 'nullable|numeric',
            'status'      => 'required|string',
            'sort'        => 'integer',
            'meta'        => 'nullable|array',
            'category'    => 'nullable|array',
        ]);

        // 同步分类（如果不存在则创建）
        $categoryId = null;
        if (! empty($data['category'])) {
            $cat = Category::updateOrCreate(
                ['slug' => $data['category']['slug']],
                [
                    'name'   => $data['category']['name'],
                    'slug'   => $data['category']['slug'],
                    'status' => 'active',
                ]
            );
            $categoryId = $cat->id;
        }

        // Upsert 商品
        $product = Product::updateOrCreate(
            ['id' => $data['id']],
            [
                'name'        => $data['name'],
                'slug'        => $data['slug'],
                'description' => $data['description'] ?? null,
                'category_id' => $categoryId,
                'base_price'  => $data['base_price'],
                'sale_price'  => $data['sale_price'] ?? null,
                'status'      => $data['status'],
                'sort'        => $data['sort'] ?? 0,
                'meta'        => $data['meta'] ?? null,
            ]
        );

        Log::info("[Sync] 商品同步完成: #{$product->id} {$product->name}");

        return response()->json(['id' => $product->id], 200);
    }

    /**
     * 接收 🇨🇳 Admin 推送的变体和图片同步。
     */
    public function syncVariants(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer',
            'variants'   => 'required|array',
            'images'     => 'nullable|array',
        ]);

        $product = Product::find($data['product_id']);
        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        // 变体全量替换
        $product->variants()->delete();
        foreach ($data['variants'] as $v) {
            $product->variants()->create([
                'sku'   => $v['sku'],
                'color' => $v['color'] ?? '',
                'size'  => $v['size'] ?? '',
                'price' => $v['price'] ?? null,
                'stock' => $v['stock'] ?? 0,
                'image' => $v['image'] ?? null,
            ]);
        }

        // 图片全量替换
        if (isset($data['images'])) {
            $product->images()->delete();
            foreach ($data['images'] as $img) {
                $product->images()->create([
                    'url'        => $img['url'],
                    'alt'        => $img['alt'] ?? null,
                    'sort'       => $img['sort'] ?? 0,
                    'is_primary' => $img['is_primary'] ?? false,
                ]);
            }
        }

        Log::info("[Sync] 商品 #{$product->id} 变体/图片同步: " . count($data['variants']) . ' variants');

        return response()->json(['message' => 'Variants synced.'], 200);
    }

    /**
     * 接收 🇨🇳 Admin 的删除指令。
     */
    public function destroy(int $id): JsonResponse
    {
        $product = Product::find($id);
        if ($product) {
            $product->delete();
            Log::info("[Sync] 商品 #{$id} 已删除");
        }
        return response()->json(null, 204);
    }
}
