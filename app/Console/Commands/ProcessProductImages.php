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

        $publicDir = base_path('../web-store/public');
        $images = $product->images()->whereNotNull('product_skc_id')->get();

        if ($images->isEmpty()) {
            $this->warn('无图片。');
            return self::SUCCESS;
        }

        $this->info("商品 #{$product->id} {$product->name} — {$images->count()} 张图片");

        $manifest = [];
        foreach ($images as $img) {
            $manifest[] = $publicDir . $img->url;
        }

        $manifestFile = storage_path("app/temp/imagegen-manifest-{$product->id}.json");
        file_put_contents($manifestFile, json_encode([
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'face_ref'     => '/var/www/arelonne/docs/references/oglmove/arelonne-face-source-aligned-v1.png',
            'images'       => $manifest,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line("清单: {$manifestFile}");
        $this->line("接下来用 Codex 批量处理:");
        $this->line("  codex exec --skip-git-repo-check \"读取 {$manifestFile}，对其中每张图：有脸则用 imagegen edit 换脸 + GD 背景去图标，无脸则只做 GD 背景去图标。处理完覆盖原文件。\"");

        return self::SUCCESS;
    }
}
