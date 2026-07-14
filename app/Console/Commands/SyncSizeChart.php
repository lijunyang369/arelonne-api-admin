<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\SizeChartValidator;
use App\Services\SyncService;
use Illuminate\Console\Command;

/**
 * 从 JSON 文件同步商品尺码数据。
 *
 * 用法：php artisan sync:size-chart 5 ./size-data.json
 */
class SyncSizeChart extends Command
{
    protected $signature = 'sync:size-chart
                            {productId : 本地商品 ID}
                            {file : Codex 输出的 size-data.json 文件路径}';

    protected $description = '从 JSON 文件导入商品尺码标签和测量数据，并推送到 Store';

    /**
     * 执行同步。
     */
    public function handle(SizeChartValidator $validator): int
    {
        $productId = (int) $this->argument('productId');
        $filePath  = $this->argument('file');

        // 1. 读取 JSON 文件
        if (! file_exists($filePath)) {
            $this->error("文件不存在: {$filePath}");
            return self::FAILURE;
        }

        $json = json_decode(file_get_contents($filePath), true);
        if (! is_array($json)) {
            $this->error('JSON 解析失败，请检查文件格式。');
            return self::FAILURE;
        }

        // 2. 校验
        try {
            $data = $validator->validate($json);
        } catch (\InvalidArgumentException $e) {
            $this->error('校验失败: ' . $e->getMessage());
            return self::FAILURE;
        }

        // 3. 查找商品
        $product = Product::findOrFail($productId);
        $this->info("商品: #{$product->id} {$product->name}");

        // 4. Upsert variants（按 product_id + size 去重，不修改 stock）
        $created = 0;
        foreach ($data['sizes'] as $size) {
            $variant = $product->variants()->updateOrCreate(
                [
                    'size' => $size,
                ],
                [
                    'sku'   => $product->slug . '-' . $size,
                    'color' => null,
                    'price' => null,      // 使用 product base_price
                    // stock 不写入
                ]
            );
            if ($variant->wasRecentlyCreated) {
                $created++;
            }
        }
        $this->info("尺码标签: {$created} 个新增（共 " . count($data['sizes']) . " 个）");

        // 5. 写入 meta.size_chart
        $product->update([
            'meta' => array_merge($product->meta ?? [], [
                'size_chart' => $data['size_chart'],
            ]),
        ]);
        $this->info('size_chart 已写入 meta');

        // 6. 推送到 Store
        $this->info('推送至 Store...');

        // 6a. 推送 product（meta 变更）
        SyncService::pushAsync(
            "/products/{$product->id}",
            $product->only([
                'id', 'name', 'slug', 'description', 'base_price',
                'sale_price', 'status', 'sort', 'meta',
            ]),
            'PUT'
        );

        // 6b. 推送 variants（全量：收集所有 variant 后发送）
        $allVariants = $product->variants()->get()->map(fn ($v) => [
            'sku'   => $v->sku,
            'color' => $v->color,
            'size'  => $v->size,
            'price' => $v->price,
            'stock' => $v->stock,
            'image' => $v->image,
        ])->toArray();

        SyncService::pushAsync(
            "/products/{$product->id}/variants",
            [
                'product_id' => $product->id,
                'variants'   => $allVariants,
            ]
        );

        $this->info('完成。');
        return self::SUCCESS;
    }
}
