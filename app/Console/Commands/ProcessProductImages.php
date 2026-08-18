<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * 对已同步的商品图片做 Codex imagegen 后处理。
 *
 * 用法：
 *   php artisan sync:process-images 9
 */
class ProcessProductImages extends Command
{
    protected $signature = 'sync:process-images {productId}';
    protected $description = '输出后处理清单，由外部 Codex 会话批量处理';

    public function handle(): int
    {
        $productId = (int) $this->argument('productId');
        $product = Product::findOrFail($productId);

        $stagingRoot = (string) config('image.staging_root');
        $manifests = glob("{$stagingRoot}/images/products/{$product->slug}/*/manifest.json") ?: [];

        if (count($manifests) === 0) {
            $this->warn("无待处理 manifest（先跑 sync:product-images）。");
            return self::SUCCESS;
        }
        if (count($manifests) > 1) {
            $this->error('该商品有多个 manifest，无法确定处理目标，请清理暂存区。');
            foreach ($manifests as $m) {
                $this->line("  {$m}");
            }
            return self::FAILURE;
        }

        $manifestPath = $manifests[0];
        $data = json_decode((string) file_get_contents($manifestPath), true);
        $stagingDir = dirname($manifestPath);

        // 由 manifest 字段构造清单（不再读 DB）
        $images = [];
        foreach ($data['skcs'] ?? [] as $skc) {
            foreach ($skc['images'] ?? [] as $img) {
                $images[] = "{$stagingDir}/{$skc['slug']}/{$img['file']}";
            }
        }

        if (empty($images)) {
            $this->warn('manifest 中无图片。');
            return self::SUCCESS;
        }

        $this->info("商品 #{$product->id} {$product->name} — " . count($images) . ' 张图片');

        $manifestFile = storage_path("app/temp/imagegen-manifest-{$product->id}.json");
        file_put_contents($manifestFile, json_encode([
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'face_ref'     => '/var/www/arelonne/docs/references/oglmove/arelonne-face-source-aligned-v1.png',
            'images'       => $images,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line("清单: {$manifestFile}");
        $this->line("接下来用 Codex 批量处理:");
        $this->line("  codex exec --skip-git-repo-check \"读取 {$manifestFile}，对其中每张图：有脸则用 imagegen edit 换脸 + GD 背景去图标，无脸则只做 GD 背景去图标。处理完覆盖原文件。\"");

        return self::SUCCESS;
    }
}
