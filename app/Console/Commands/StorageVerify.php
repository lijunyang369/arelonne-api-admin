<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use App\Models\Upload;
use App\Support\ImageVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * 全量一致性校验：DB 图片路径与图片磁盘对象。
 *
 * - product_images：原图 + 全部档位变体（三档恒生成契约，任何缺失都算失败）
 * - uploads.confirmed：final_path + 全部档位变体
 * - --json：机器可读报告（部署门禁用）
 *
 * 用法：
 *   php artisan storage:verify
 *   php artisan storage:verify --json
 */
class StorageVerify extends Command
{
    protected $signature = 'storage:verify {--json : 输出 JSON 报告}';

    protected $description = '比对 DB 图片 url 与图片磁盘对象，报告缺失';

    /**
     * 执行校验。
     */
    public function handle(): int
    {
        $disk = Storage::disk('image');
        $missing = [];

        // 1. 商品图（chunkById 防大表内存）
        ProductImage::query()->with('product')->chunkById(500, function ($images) use ($disk, &$missing) {
            foreach ($images as $img) {
                foreach ($this->expectedPaths($img->url) as $path) {
                    if (! $disk->exists($path)) {
                        $missing[] = ['type' => 'product_image', 'id' => $img->id, 'path' => $path];
                    }
                }
            }
        });

        // 2. 已确认上传（含元数据完整性：final/thumb 路径、宽高缺一即失败）
        Upload::query()->where('status', 'confirmed')->chunkById(500, function ($uploads) use ($disk, &$missing) {
            foreach ($uploads as $upload) {
                if (empty($upload->final_path)) {
                    $missing[] = ['type' => 'upload_metadata', 'id' => $upload->id, 'path' => '(final_path 为空)'];
                    continue;
                }
                if (empty($upload->thumb_path)) {
                    $missing[] = ['type' => 'upload_metadata', 'id' => $upload->id, 'path' => '(thumb_path 为空)'];
                }
                if (empty($upload->width) || empty($upload->height)) {
                    $missing[] = ['type' => 'upload_metadata', 'id' => $upload->id, 'path' => '(width/height 缺失)'];
                }
                foreach ($this->expectedPaths($upload->final_path) as $path) {
                    if (! $disk->exists($path)) {
                        $missing[] = ['type' => 'upload', 'id' => $upload->id, 'path' => $path];
                    }
                }
            }
        });

        if ($this->option('json')) {
            $this->line(json_encode([
                'missing_count' => count($missing),
                'missing'       => $missing,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return empty($missing) ? self::SUCCESS : self::FAILURE;
        }

        foreach ($missing as $m) {
            $this->error("  ✗ 缺失 [{$m['type']} #{$m['id']}]: {$m['path']}");
        }

        if (empty($missing)) {
            $this->info('全部一致，无缺失。');

            return self::SUCCESS;
        }

        $this->error(count($missing) . ' 个对象缺失。');

        return self::FAILURE;
    }

    /**
     * 路径 → 应存在的全部对象路径（原图 + 三档变体）。
     *
     * @return array<int, string>
     */
    private function expectedPaths(?string $url): array
    {
        if ($url === null || $url === '') {
            return [];
        }

        $url = ltrim($url, '/');

        return array_merge(
            [$url],
            array_map(
                fn (int $width) => ImageVariants::variantPath($url, $width),
                (array) config('image.widths', [480, 960, 1600])
            )
        );
    }
}
