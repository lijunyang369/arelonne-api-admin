<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductSkc;
use App\Services\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * 从 Codex 输出的 JSON 文件同步商品 SKC 颜色图片数据。
 *
 * 用法：php artisan sync:product-images 6 ./skc-data.json
 */
class SyncProductImages extends Command
{
    protected $signature = 'sync:product-images
                            {productId : 本地商品 ID}
                            {file : Codex 输出的 JSON 文件路径}';

    protected $description = '从 JSON 文件导入 SKC 颜色变体和图片，下载图片到本地，并推送到 Store';

    /**
     * 执行同步。
     */
    public function handle(): int
    {
        $productId = (int) $this->argument('productId');
        $filePath  = $this->argument('file');

        // 1. 读取 JSON
        if (! file_exists($filePath)) {
            $this->error("文件不存在: {$filePath}");
            return self::FAILURE;
        }

        $json = json_decode(file_get_contents($filePath), true);
        if (! is_array($json) || ! isset($json['colors'])) {
            $this->error('JSON 格式错误，缺少 colors 字段。');
            return self::FAILURE;
        }

        // 2. 查找商品
        $product = Product::findOrFail($productId);
        $this->info("商品: #{$product->id} {$product->name}");

        // 3. 确保图片目录存在（图片保存到 web-store 的 public 目录，由 Next.js 直接提供）
        $baseDir = base_path('../web-store/public/images/products/' . $product->slug);
        if (! is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }

        // 4. 处理每个颜色
        $skcCreated = 0;
        $skcUpdated = 0;
        $imgTotal   = 0;

        foreach ($json['colors'] as $colorData) {
            $color    = $colorData['color'];
            $colorHex = $colorData['color_hex'] ?? null;
            $images   = $colorData['images'] ?? [];

            // 4a. upsert SKC
            $skcSlug = $product->slug . '-' . \Str::slug($color);
            $skc = $product->skcs()->updateOrCreate(
                ['product_id' => $product->id, 'color' => $color],
                [
                    'slug'      => $skcSlug,
                    'color_hex' => $colorHex,
                    'status'    => 'active',
                ]
            );

            if ($skc->wasRecentlyCreated) {
                $skcCreated++;
                $this->line("  + SKC: {$color}");
            } else {
                $skcUpdated++;
                $this->line("  ~ SKC: {$color}");
            }

            // 4b. 删除该 SKC 的旧图片
            $skc->images()->delete();

            // 4c. 下载图片 + 写入记录
            $skcDir = "{$baseDir}/{$skcSlug}";
            if (! is_dir($skcDir)) {
                mkdir($skcDir, 0755, true);
            }

            foreach ($images as $i => $img) {
                $url       = $img['url'];
                $alt       = $img['alt'] ?? null;
                $sort      = $img['sort'] ?? $i;
                $isPrimary = $img['is_primary'] ?? false;

                // 下载图片
                $localPath = $this->downloadImage($url, $skcDir, $skcSlug, $i);

                // 写入 product_images
                $product->images()->create([
                    'product_skc_id' => $skc->id,
                    'url'            => $localPath,
                    'alt'            => $alt,
                    'sort'           => $sort,
                    'is_primary'     => $isPrimary,
                ]);

                $imgTotal++;
                $this->line("    📷 {$localPath}");
            }
        }

        $this->info("SKC: {$skcCreated} 新增, {$skcUpdated} 更新");
        $this->info("图片: {$imgTotal} 张");

        // 5. 推送 Store：SKC + images
        $this->info('推送至 Store...');

        $skcsData = $product->skcs()->with('images')->get()->map(fn ($skc) => [
            'id'        => $skc->id,
            'color'     => $skc->color,
            'color_hex' => $skc->color_hex,
            'slug'      => $skc->slug,
            'status'    => $skc->status,
            'sort'      => $skc->sort,
            'images'    => $skc->images->map(fn ($img) => [
                'id'         => $img->id,
                'url'        => $img->url,
                'alt'        => $img->alt,
                'sort'       => $img->sort,
                'is_primary' => $img->is_primary,
            ])->toArray(),
        ])->toArray();

        SyncService::pushAsync(
            "/products/{$product->id}/skcs",
            [
                'product_id' => $product->id,
                'skcs'       => $skcsData,
            ]
        );

        $this->info('完成。');
        return self::SUCCESS;
    }

    /**
     * 下载图片到本地目录。
     *
     * @param  string  $url      远程图片 URL
     * @param  string  $dir      目标目录
     * @param  string  $skcSlug  SKC slug
     * @param  int     $index    图片序号
     * @return string  本地路径 /images/products/...
     */
    private function downloadImage(string $url, string $dir, string $skcSlug, int $index): string
    {
        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $ext = 'jpg';
        }

        $filename = sprintf('%s-%02d.%s', $skcSlug, $index + 1, $ext);
        $filePath = "{$dir}/{$filename}";

        // 如果已是本地路径，跳过下载
        if (str_starts_with($url, '/images/') || str_starts_with($url, 'http://localhost')) {
            return $url;
        }

        try {
            $response = Http::timeout(30)->get($url);
            if ($response->successful()) {
                file_put_contents($filePath, $response->body());
            }
        } catch (\Throwable $e) {
            $this->warn("    下载失败: {$url} — {$e->getMessage()}");
        }

        // 返回前端可访问的相对路径（对应 web-store/public 下的文件）
        $webStorePublic = base_path('../web-store/public');
        return str_replace($webStorePublic, '', $filePath);
    }
}
