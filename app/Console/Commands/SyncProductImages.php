<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * 从 oglmove 商品页自动同步 SKC 颜色图片到 Arelonne。
 *
 * 流程：
 *   1. 调用 Shopify .json API 获取 variant / image 数据
 *   2. 通过 variant_ids 把图片映射到对应颜色
 *   3. variant_ids=[] 的图片 → Codex 视觉分析确定颜色
 *   4. 下载图片、写入 SKC + product_images、推送到 Store
 *
 * 用法：
 *   php artisan sync:product-images https://oglmove.com/products/halter-neck-banded-hem-bra-tank-bra-top 6
 */
class SyncProductImages extends Command
{
    protected $signature = 'sync:product-images
                            {url : oglmove 商品页 URL}
                            {productId : Arelonne 本地商品 ID}';

    protected $description = '从 oglmove Shopify API 自动同步 SKC 颜色图片';

    /** 已知的 oglmove 颜色名（Codex 分析时会从中选择） */
    private array $knownColors = [];

    /**
     * 执行同步。
     */
    public function handle(): int
    {
        $url       = $this->argument('url');
        $productId = (int) $this->argument('productId');

        // 1. 查找本地商品
        $product = Product::findOrFail($productId);
        $this->info("商品: #{$product->id} {$product->name}");

        // 2. 调用 Shopify .json API
        $apiUrl = $this->shopifyJsonUrl($url);
        $this->line("API: {$apiUrl}");

        try {
            $response = Http::timeout(30)->get($apiUrl);
        } catch (\Throwable $e) {
            $this->error("Shopify API 连接失败: {$e->getMessage()}");
            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("Shopify API 请求失败: HTTP {$response->status()}");
            return self::FAILURE;
        }

        $raw  = $response->json();
        $data = $raw['product'] ?? null;
        if (! $data) {
            $this->error('API 响应缺少 product 字段。');
            return self::FAILURE;
        }

        $this->line('已获取商品数据');

        // 3. 构建 variant_id → color 映射
        $variantColor = [];
        $variants = $data['variants'] ?? [];
        if (empty($variants)) {
            $this->error('Shopify API 返回的 variants 为空，无法同步。');
            return self::FAILURE;
        }
        foreach ($variants as $v) {
            $variantColor[$v['id']] = $v['option1'];
        }
        $this->knownColors = array_values(array_unique($variantColor));
        if (empty($this->knownColors)) {
            $this->error('无法从 variants 中提取颜色信息。');
            return self::FAILURE;
        }
        $this->line('颜色列表: ' . implode(', ', $this->knownColors));

        // 4. 图片 → 颜色分组（依据 variant_ids）
        $colorImages = [];  // color → [{url, alt}, ...]
        $orphans     = [];  // variant_ids=[] 的图片，需要 Codex 视觉分析

        $images = $data['images'] ?? [];
        foreach ($images as $img) {
            $src = $img['src'] ?? null;
            if ($src === null) {
                $this->warn('  跳过无 src 的图片');
                continue;
            }
            $imgData = [
                'url' => $src,
                'alt' => $img['alt'] ?? '',
            ];

            $vids = $img['variant_ids'] ?? [];
            if (empty($vids)) {
                $orphans[] = $imgData;
            } else {
                $colors = [];
                foreach ($vids as $vid) {
                    if (isset($variantColor[$vid])) {
                        $colors[] = $variantColor[$vid];
                    }
                }
                foreach (array_unique($colors) as $c) {
                    $colorImages[$c][] = $imgData;
                }
            }
        }

        $this->line(sprintf(
            '图片映射: %d 张有明确颜色, %d 张需 Codex 分析',
            array_sum(array_map('count', $colorImages)),
            count($orphans)
        ));

        // 5. Codex 视觉分析 orphans
        $orphanTempPaths = []; // orphan index → temp file path（避免二次下载）
        if (! empty($orphans)) {
            $result = $this->analyzeOrphansWithCodex($orphans, $data['title'] ?? '');
            $orphanMap = $result['map'];
            $orphanTempPaths = $result['temp_paths'];
            foreach ($orphanMap as $color => $imgs) {
                foreach ($imgs as &$imgData) {
                    // 挂上 tmp_path，downloadImage 会优先 move 而非重新下载
                    $imgData['tmp_path'] = $orphanTempPaths[$imgData['_orphan_idx']] ?? null;
                }
                unset($imgData);
                $colorImages[$color] = array_merge($colorImages[$color] ?? [], $imgs);
            }
        }

        // 6. 确保图片根目录存在
        $baseDir = base_path('../web-store/public/images/products/' . $product->slug);
        if (! is_dir($baseDir) && ! mkdir($baseDir, 0755, true) && ! is_dir($baseDir)) {
            $this->error("无法创建图片根目录: {$baseDir}");
            return self::FAILURE;
        }

        // 7. 写入 SKC + 下载图片（事务保护：先下载全部 → 再原子替换 DB 记录）
        $skcCreated = 0;
        $skcUpdated = 0;
        $imgTotal   = 0;
        $imgFailed  = 0;

        foreach ($colorImages as $color => $images) {
            $skcSlug = $product->id . '-' . $product->slug . '-' . Str::slug($color);

            // 先下载所有图片到磁盘
            $skcDir = "{$baseDir}/{$skcSlug}";
            if (! is_dir($skcDir) && ! mkdir($skcDir, 0755, true) && ! is_dir($skcDir)) {
                $this->error("  无法创建目录: {$skcDir}，跳过此 SKC。");
                continue;
            }

            $downloaded = [];
            foreach ($images as $i => $img) {
                $localPath = $this->downloadImage($img['url'], $skcDir, $skcSlug, $i, $img['tmp_path'] ?? null);
                if ($localPath === null) {
                    $imgFailed++;
                    continue;
                }
                $downloaded[] = [
                    'path'       => $localPath,
                    'alt'        => $img['alt'] ?? null,
                    'sort'       => $i,
                    'is_primary' => ($i === 0),
                ];
            }

            // 没有成功下载的图片 → 保留旧数据，跳过此 SKC
            if (empty($downloaded)) {
                $this->line("  ! SKC: {$color} — 所有图片下载失败，保留旧数据");
                continue;
            }

            // 事务内：原子替换 DB 记录
            DB::transaction(function () use (
                $product, $color, $skcSlug, $downloaded,
                &$skcCreated, &$skcUpdated, &$imgTotal
            ) {
                $skc = $product->skcs()->updateOrCreate(
                    ['product_id' => $product->id, 'color' => $color],
                    [
                        'slug'      => $skcSlug,
                        'color_hex' => $this->guessHex($color),
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

                $skc->images()->delete();
                foreach ($downloaded as $d) {
                    $product->images()->create([
                        'product_skc_id' => $skc->id,
                        'url'            => $d['path'],
                        'alt'            => $d['alt'],
                        'sort'           => $d['sort'],
                        'is_primary'     => $d['is_primary'],
                    ]);
                    $imgTotal++;
                    $this->line("    📷 {$d['path']}");
                }
            });
        }

        // 清理 Codex 临时目录中已被 move 的残余文件
        $this->cleanupTempDir(storage_path('app/temp/skc-analyze'));

        $this->info("SKC: {$skcCreated} 新增, {$skcUpdated} 更新");
        $this->info("图片: {$imgTotal} 张" . ($imgFailed > 0 ? "，{$imgFailed} 张下载失败" : ''));

        // 8. 推送 Store
        if ($imgTotal === 0) {
            $this->warn('没有成功下载的图片，跳过 Store 推送。');
            return self::SUCCESS;
        }

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

        $pushed = SyncService::push(
            "/products/{$product->id}/skcs",
            [
                'product_id' => $product->id,
                'skcs'       => $skcsData,
            ]
        );

        if (! $pushed) {
            $this->error('推送 Store 失败！请检查 Store 服务状态和日志。');
            return self::FAILURE;
        }

        $this->info('完成。');
        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    /**
     * 将 oglmove 商品页 URL 转换为 Shopify .json API URL。
     */
    private function shopifyJsonUrl(string $url): string
    {
        $host   = parse_url($url, PHP_URL_HOST) ?: 'oglmove.com';
        $handle = basename(parse_url($url, PHP_URL_PATH) ?: '');
        return "https://{$host}/products/{$handle}.json";
    }

    /**
     * 叫 Codex 视觉分析 orphan 图片，返回 color → [images] 映射。
     *
     * @param  array  $orphans  [{url, alt}, ...]
     * @param  string $title   商品名（给 Codex 的上下文）
     * @return array  ['颜色名' => [{url,alt}, ...]]
     */
    private function analyzeOrphansWithCodex(array $orphans, string $title): array
    {
        $tempDir = storage_path('app/temp/skc-analyze');
        if (! is_dir($tempDir) && ! mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
            $this->error("  无法创建临时目录: {$tempDir}");
            return $this->fallbackOrphans('无法创建临时目录', $orphans);
        }

        // 下载 orphan 图片到临时目录
            $orphanFiles = [];
            foreach ($orphans as $i => $img) {
                $filename = "orphan-{$i}.jpg";
                $filepath = "{$tempDir}/{$filename}";
                if (! file_exists($filepath)) {
                    try {
                        $resp = Http::timeout(30)->get($img['url']);
                        if ($resp->successful()) {
                            file_put_contents($filepath, $resp->body());
                        } else {
                            $this->warn("  下载 orphan 失败 HTTP {$resp->status()}: {$img['url']}");
                            continue;
                        }
                    } catch (\Throwable $e) {
                        $this->warn("  下载 orphan 失败: {$img['url']} — {$e->getMessage()}");
                        continue;
                    }
                }
                $orphanFiles[] = $filename;
            }

            if (empty($orphanFiles)) {
                return ['map' => [], 'temp_paths' => []];
            }

            $fileList = implode(', ', $orphanFiles);
            $colorList = implode(', ', $this->knownColors);

            $prompt = <<<PROMPT
分析 {$tempDir} 目录下的图片（{$fileList}）。

这些是女装商品 "{$title}" 的产品图。

已知这个商品有这些颜色：{$colorList}

请逐一分析每张图片中模特穿着的商品颜色，判断属于上述颜色中的哪一个。
输出一个 JSON 文件到 {$tempDir}/codex-result.json，格式：
{
  "orphan-0.jpg": "颜色名",
  "orphan-1.jpg": "颜色名"
}
PROMPT;

            $this->line('  调用 Codex 视觉分析...');

            $process = new Process([
                'codex', 'exec', '--skip-git-repo-check', $prompt,
            ], base_path());

            $process->setTimeout(600);
            $process->setInput('');
            $process->run();

            if (! $process->isSuccessful()) {
                return $this->fallbackOrphans('Codex 分析失败: ' . $process->getErrorOutput(), $orphans);
            }

            $resultFile = "{$tempDir}/codex-result.json";
            if (! file_exists($resultFile)) {
                return $this->fallbackOrphans('Codex 未生成结果文件，回退到第一个颜色。', $orphans);
            }

            $map = json_decode(file_get_contents($resultFile), true);
            if (! is_array($map)) {
                return $this->fallbackOrphans('Codex 结果格式异常，回退到第一个颜色。', $orphans);
            }

            // 构建 temp_paths 映射，供主流程避免二次下载
            $tempPaths = [];
            foreach ($orphans as $i => $img) {
                $filepath = "{$tempDir}/orphan-{$i}.jpg";
                if (file_exists($filepath)) {
                    $tempPaths[$i] = $filepath;
                }
            }

            $result = [];
            foreach ($orphans as $i => $img) {
                $filename = "orphan-{$i}.jpg";
                $color = $map[$filename] ?? ($this->knownColors[0] ?? 'Unknown');
                $img['_orphan_idx'] = $i;
                $result[$color][] = $img;
            }

            $this->line('  Codex 分析完成: ' . json_encode(array_map('count', $result)));
            return ['map' => $result, 'temp_paths' => $tempPaths];
    }

    /**
     * Codex 分析失败时的回退策略：将所有 orphan 图归到第一个颜色。
     */
    private function fallbackOrphans(string $reason, array $orphans): array
    {
        $this->warn("  {$reason}");
        $fallback = $this->knownColors[0] ?? 'Unknown';
        // 给 orphan 标索引，构建 temp_paths
        $result = [];
        $tempPaths = [];
        foreach ($orphans as $i => $img) {
            $img['_orphan_idx'] = $i;
            $result[$fallback][] = $img;
            $filepath = storage_path("app/temp/skc-analyze/orphan-{$i}.jpg");
            if (file_exists($filepath)) {
                $tempPaths[$i] = $filepath;
            }
        }
        return ['map' => $result, 'temp_paths' => $tempPaths];
    }

    /**
     * 清理临时目录。
     */
    private function cleanupTempDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = glob("{$dir}/*");
        foreach ($files ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    /**
     * 颜色名 → hex 值（从品牌 Color 表查询，找不到返回 null）。
     */
    private function guessHex(string $color): ?string
    {
        return \App\Models\Color::where('name', $color)->value('hex');
    }

    /**
     * 下载单张图片到本地目录。
     *
     * @return string|null  相对路径，失败时返回 null
     */
    private function downloadImage(string $url, string $dir, string $skcSlug, int $index, ?string $tmpPath = null): ?string
    {
        $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION);
        if (! in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp'])) {
            $ext = 'jpg';
        }

        $filename = sprintf('%s-%02d.%s', $skcSlug, $index + 1, $ext);
        $filePath = "{$dir}/{$filename}";

        // 本地路径原样返回
        if (str_starts_with($url, '/images/') || str_starts_with($url, 'http://localhost')) {
            return $url;
        }

        // 已存在则跳过下载
        if (file_exists($filePath)) {
            return $this->webRelativePath($filePath);
        }

        // 优先从 Codex 临时目录 move（避免二次下载 orphan 图片）
        if ($tmpPath !== null && file_exists($tmpPath)) {
            rename($tmpPath, $filePath);
            return $this->webRelativePath($filePath);
        }

        // 下载
        try {
            $response = Http::timeout(30)->get($url);
            if ($response->successful()) {
                file_put_contents($filePath, $response->body());
            } else {
                $this->warn("    下载失败 HTTP {$response->status()}: {$url}");
                return null;
            }
        } catch (\Throwable $e) {
            $this->warn("    下载失败: {$url} — {$e->getMessage()}");
            return null;
        }

        return $this->webRelativePath($filePath);
    }

    /**
     * 将绝对路径转为前端可访问的 web 相对路径。
     */
    private function webRelativePath(string $absolutePath): string
    {
        $publicDir = base_path('../web-store/public');
        if (str_starts_with($absolutePath, $publicDir)) {
            return substr($absolutePath, strlen($publicDir));
        }
        return $absolutePath;
    }
}
