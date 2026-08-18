<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * 商品编辑页的 SKC/图片快照持久化（运营手工编辑场景）。
 *
 * 与导入管线的 publish 命令区分：无 pid 版本化、无对象上传——URL 已由
 * 上传组件（presign/confirm）或手工填写产生，这里只负责表单数据落库。
 */
class ProductSkuImageService
{
    /**
     * 以表单快照替换商品的 SKC 与图片（事务内；软删行恢复复用）。
     *
     * @param  array<int, array{color:string, color_hex:?string, slug:string, images:array<int, array{url:string, alt:?string, sort:int, is_primary:bool}>}>  $skcs
     */
    public function replaceFromForm(Product $product, array $skcs): void
    {
        DB::transaction(function () use ($product, $skcs) {
            $newColors = collect($skcs)->pluck('color');

            // 删除表单中不存在的颜色（软删；唯一索引仍在，重新加入时靠 withTrashed 恢复）
            $product->skcs()->whereNotIn('color', $newColors)->delete();

            foreach ($skcs as $skcData) {
                $model = $product->skcs()->withTrashed()->updateOrCreate(
                    ['product_id' => $product->id, 'color' => $skcData['color']],
                    [
                        'slug'       => $skcData['slug'],
                        'color_hex'  => $skcData['color_hex'] ?? null,
                        'status'     => 'active',
                        'deleted_at' => null,
                    ]
                );
                if ($model->trashed()) {
                    $model->restore();
                }

                // 图片快照替换（同 SKC 内）
                $model->images()->delete();
                foreach ($skcData['images'] ?? [] as $i => $imgData) {
                    $product->images()->create([
                        'product_skc_id' => $model->id,
                        'url'            => $imgData['url'],
                        'alt'            => $imgData['alt'] ?? null,
                        'sort'           => $imgData['sort'] ?? $i,
                        'is_primary'     => (bool) ($imgData['is_primary'] ?? false),
                    ]);
                }
            }
        });
    }
}
