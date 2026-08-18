<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ImageVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 发布暂存区商品图：一致性校验 → 上传 → DB 快照替换 → 回收旧版本 → 清暂存。
 *
 * 幂等与补偿规则（对象先行、DB 后行）：
 * - 事务前失败：回滚本次已写对象，保留 staging/manifest，重跑覆盖写安全
 * - 全图被拒：不发布、不回收旧版本、保留 staging
 *
 * 用法：
 *   php artisan sync:publish-images 9            # 该商品唯一 manifest
 *   php artisan sync:publish-images 9 --pid=abcd1234   # 多个 manifest 时精确指定
 *   php artisan sync:publish-images --all
 */
class PublishImages extends Command
{
    protected $signature = 'sync:publish-images
                            {productId? : 商品 ID}
                            {--all : 发布全部商品}
                            {--pid= : 精确指定发布 ID（商品有多个 manifest 时）}';

    protected $description = '发布暂存区商品图（上传+校验 → DB 快照替换 → 回收旧版本）';

    /** 版本化资源缓存头（长缓存 immutable） */
    private const IMMUTABLE = 'public, max-age=31536000, immutable';

    /**
     * 执行发布。
     */
    public function handle(): int
    {
        $stagingRoot = (string) config('image.staging_root');

        $manifests = collect(glob("{$stagingRoot}/images/products/*/*/manifest.json") ?: []);

        if (! $this->option('all')) {
            // 单商品：按 manifest 的 product_id 定位（商品可能被改名，目录 slug 不可靠；
            // slug 漂移由 validatedManifest 校验拒绝）
            $productId = (int) $this->argument('productId');
            Product::findOrFail($productId); // 商品不存在快速失败
            $manifests = $manifests->filter(function (string $m) use ($productId) {
                $data = json_decode((string) File::get($m), true);

                return is_array($data) && (int) ($data['product_id'] ?? 0) === $productId;
            });
        }

        $pidOption = (string) ($this->option('pid') ?? '');
        if ($pidOption !== '') {
            $manifests = $manifests->filter(fn (string $m) => basename(dirname($m)) === $pidOption);
        }

        if ($manifests->isEmpty()) {
            $this->warn('无待发布 manifest。');

            return self::FAILURE;
        }

        // 按商品分组：多 manifest 需 --pid 指定或清理暂存区
        $groups = $manifests->groupBy(function (string $m) {
            $data = json_decode((string) File::get($m), true);

            return is_array($data) ? (int) ($data['product_id'] ?? 0) : 0;
        });

        $exit = self::SUCCESS;

        foreach ($groups as $productId => $group) {
            if ($group->count() > 1) {
                $this->error("商品 #{$productId}: 有 {$group->count()} 个 manifest，请用 --pid 指定或清理暂存区后重试：");
                foreach ($group as $m) {
                    $this->line("  {$m}");
                }
                $exit = self::FAILURE;
                continue;
            }

            if ($this->publishOne($group->first()) !== self::SUCCESS) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }

    /**
     * 发布单个商品（一个 manifest）。
     */
    private function publishOne(string $manifestPath): int
    {
        // 商品级锁：防两个发布进程交错同一商品
        $lockDir = storage_path('app/publish-locks');
        File::ensureDirectoryExists($lockDir);
        $lockFile = fopen("{$lockDir}/{$this->lockName($manifestPath)}.lock", 'c');
        if (! flock($lockFile, LOCK_EX | LOCK_NB)) {
            $this->error("{$manifestPath}: 该商品正在发布中（锁被占用）。");
            fclose($lockFile);
            return self::FAILURE;
        }

        try {
            return $this->publishOneLocked($manifestPath);
        } finally {
            flock($lockFile, LOCK_UN);
            fclose($lockFile);
        }
    }

    /**
     * 持锁状态下的单商品发布。
     */
    private function publishOneLocked(string $manifestPath): int
    {
        // 1. 一致性校验（slug 漂移/篡改防护；canonical key 唯一事实源）
        $data = $this->validatedManifest($manifestPath);
        if ($data === null) {
            return self::FAILURE;
        }

        $product = Product::find($data['product_id']);
        if (! $product) {
            $this->error("商品 #{$data['product_id']} 不存在: {$manifestPath}");
            return self::FAILURE;
        }

        $stagingDir = dirname($manifestPath);
        $disk = Storage::disk('image');
        $published = [];  // 本次已写对象（事务前失败回滚用）
        $rejected = [];   // 被拒绝的坏图

        // 上传阶段前采集 DB 引用的 pid 集合（回滚保护：绝不删除 DB 已引用对象——重跑场景）
        $dbPidsBefore = $product->images()->pluck('url')
            ->map(fn (string $url) => $this->pidFromUrl($url))
            ->filter()
            ->unique()
            ->all();

        $this->info("#{$product->id} {$product->name} — pid {$data['pid']}");

        // 2. 上传原图 + 三档变体（全成功才进入 DB 阶段）
        foreach ($data['skcs'] as &$skc) {
            foreach ($skc['images'] as $imgIndex => &$imgData) {
                // canonical key 由校验后的 manifest 字段构造（禁止从磁盘路径 str_replace）
                $relative = "images/products/{$data['slug']}/{$data['pid']}/{$skc['slug']}/{$imgData['file']}";
                $source = "{$stagingDir}/{$skc['slug']}/{$imgData['file']}";

                // 暂存文件缺失 → 中止（保留 staging/manifest，可修复后重跑）
                if (! file_exists($source)) {
                    $this->rollbackPublished($disk, $published, $dbPidsBefore);
                    $this->error("暂存区缺失: {$source} — 发布中止（staging 保留可重跑）。");
                    return self::FAILURE;
                }

                // 坏图整图拒绝（原图+变体都不传）
                if (@getimagesize($source) === false) {
                    $rejected[] = $relative;
                    $this->warn("  坏图拒绝: {$relative}");
                    unset($skc['images'][$imgIndex]);
                    continue;
                }

                $imgPublished = [];

                try {
                    $this->putChecked($disk, $source, $relative, self::IMMUTABLE);
                    $imgPublished[] = $relative;
                    $published[] = $relative;

                    foreach (ImageVariants::variantsFor() as $variant) {
                        $variantPath = ImageVariants::variantPath($relative, $variant['width']);
                        $variantTmpBase = tempnam(sys_get_temp_dir(), 'pub');
                        $variantTmp = $variantTmpBase . '.webp';
                        @unlink($variantTmpBase);

                        if (! ImageVariants::generate($source, $variantTmp, $variant['width'])) {
                            @unlink($variantTmp);
                            throw new \RuntimeException("变体生成失败: {$relative} ({$variant['width']})");
                        }
                        $this->putChecked($disk, $variantTmp, $variantPath, self::IMMUTABLE);
                        @unlink($variantTmp);
                        $imgPublished[] = $variantPath;
                        $published[] = $variantPath;
                    }
                } catch (\Throwable $e) {
                    // 单图失败 → 回滚该图已写对象并整图拒绝（其余图继续）
                    foreach ($imgPublished as $p) {
                        // 回滚保护（与 rollbackPublished 同语义）：重跑场景 DB 已引用对象绝不删
                        $imgPid = $this->pidFromUrl('/' . ltrim($p, '/'));
                        if ($imgPid === null || ! in_array($imgPid, $dbPidsBefore, true)) {
                            try { $disk->delete($p); } catch (\Throwable) {}
                        }
                        $published = array_values(array_filter($published, fn ($x) => $x !== $p));
                    }
                    $rejected[] = $relative;
                    $this->warn("  图片处理失败，整图拒绝: {$relative} — {$e->getMessage()}");
                    unset($skc['images'][$imgIndex]);
                    continue;
                }
            }
            // 去掉被 unset 造成的数组空洞
            $skc['images'] = array_values($skc['images']);
        }
        unset($skc, $imgData);

        // 3. 全图被拒 → 中止：不发布、不回收旧版本、保留 staging
        $hasImages = collect($data['skcs'])->contains(fn ($s) => ! empty($s['images']));
        if (! $hasImages) {
            $this->rollbackPublished($disk, $published, $dbPidsBefore);
            $this->error('全部图片被拒绝，中止发布（不更新 DB、不回收旧版本，staging 保留）。');
            return self::FAILURE;
        }

        // 4. DB 事务：完整快照替换
        $oldUrls = $product->images()->pluck('url')->all();

        DB::transaction(function () use ($product, $data) {
            // 4.1 全删旧图片
            $product->images()->delete();

            // 4.2 删除 manifest 未覆盖 color 的旧 SKC
            $newColors = collect($data['skcs'])->filter(fn ($s) => ! empty($s['images']))->pluck('color');
            $product->skcs()->whereNotIn('color', $newColors)->delete();

            // 4.3 重建快照（$product->images()->create 才带必填 product_id）
            foreach ($data['skcs'] as $skc) {
                if (empty($skc['images'])) {
                    continue;
                }
                $model = $product->skcs()->updateOrCreate(
                    ['product_id' => $product->id, 'color' => $skc['color']],
                    [
                        'slug'      => $skc['slug'],
                        'color_hex' => $skc['color_hex'],
                        'status'    => 'active',
                    ]
                );
                foreach ($skc['images'] as $imgData) {
                    $product->images()->create([
                        'product_skc_id' => $model->id,
                        'url'            => "/images/products/{$product->slug}/{$data['pid']}/{$skc['slug']}/{$imgData['file']}",
                        'alt'            => $imgData['alt'],
                        'sort'           => $imgData['sort'],
                        'is_primary'     => $imgData['is_primary'],
                    ]);
                }
            }

            // 4.4 主色 = manifest 第一个有效 SKC
            $first = collect($data['skcs'])->first(fn ($s) => ! empty($s['images']));
            if ($first) {
                $primarySkc = $product->skcs()->where('color', $first['color'])->first();
                if ($primarySkc) {
                    $product->update(['primary_skc_id' => $primarySkc->id]);
                }
            }
        });

        // 5. 回收旧 pid 对象（deleteDirectory 返回值校验，失败记日志不阻塞）
        $newPid = (string) $data['pid'];
        collect($oldUrls)
            ->map(fn (string $url) => $this->pidFromUrl($url))
            ->unique()
            ->filter(fn (?string $pid) => $pid !== null && $pid !== $newPid)
            ->each(function (string $pid) use ($disk, $product) {
                $prefix = "images/products/{$product->slug}/{$pid}";
                try {
                    if (! $disk->deleteDirectory($prefix)) {
                        Log::warning("旧版本回收失败（返回 false）: {$prefix}");
                    }
                } catch (\Throwable $e) {
                    Log::warning("旧版本回收异常: {$prefix} — {$e->getMessage()}");
                }
            });

        // 6. 清 staging 商品目录
        File::deleteDirectory($stagingDir);

        if (! empty($rejected)) {
            $this->warn(count($rejected) . ' 张图片被拒绝，DB 只含成功图。');
        }
        $this->info("#{$product->id} {$product->name}: 发布完成（pid {$newPid}）。");

        return self::SUCCESS;
    }

    /**
     * 校验 manifest 与目录/商品一致性，返回规范化数据（失败返回 null）。
     */
    private function validatedManifest(string $manifestPath): ?array
    {
        $data = json_decode((string) File::get($manifestPath), true);
        if (! is_array($data) || empty($data['product_id']) || empty($data['slug']) || empty($data['pid'])) {
            $this->error("manifest 损坏: {$manifestPath}");
            return null;
        }

        // pid 与目录名一致 + 格式合法
        $dirPid = basename(dirname($manifestPath));
        if ($data['pid'] !== $dirPid || ! preg_match('/^[a-z0-9]{8}$/', $data['pid'])) {
            $this->error("manifest pid 与目录不一致: {$manifestPath}（pid={$data['pid']} 目录={$dirPid}），请重新 sync。");
            return null;
        }

        // slug 与商品一致
        $product = Product::find((int) $data['product_id']);
        if (! $product || $product->slug !== $data['slug']) {
            $this->error("manifest slug 与商品不一致: {$manifestPath}，请重新 sync。");
            return null;
        }

        // 路径安全：skc.slug 不含 .. 与 /；file 不含 .. 与 /
        foreach ($data['skcs'] ?? [] as $skc) {
            $slug = $skc['slug'] ?? '';
            if ($slug === '' || str_contains($slug, '..') || str_contains($slug, '/')) {
                $this->error("manifest skc.slug 非法: {$slug}");
                return null;
            }
            foreach ($skc['images'] ?? [] as $img) {
                $file = $img['file'] ?? '';
                if ($file === '' || str_contains($file, '..') || str_contains($file, '/')) {
                    $this->error("manifest file 非法: {$file}");
                    return null;
                }
            }
        }

        return $data;
    }

    /**
     * 回滚已写对象（仅事务前失败路径使用）。
     * 跳过 DB 已引用 pid 的对象（重跑场景下 DB 仍指向这些对象，删除会造成 404）。
     */
    private function rollbackPublished($disk, array $published, array $protectedPids = []): void
    {
        foreach ($published as $p) {
            $pid = $this->pidFromUrl('/' . ltrim($p, '/'));
            if ($pid !== null && in_array($pid, $protectedPids, true)) {
                continue;
            }
            try { $disk->delete($p); } catch (\Throwable) {}
        }
    }

    /**
     * 锁文件名（用商品 ID，不依赖 slug）。
     */
    private function lockName(string $manifestPath): string
    {
        $data = json_decode((string) File::get($manifestPath), true);

        return (string) ($data['product_id'] ?? md5($manifestPath));
    }

    /**
     * 从 url 提取 8 位 pid：/images/products/<slug>/<pid>/...
     */
    private function pidFromUrl(string $url): ?string
    {
        return preg_match('#^/images/products/[^/]+/([a-z0-9]{8})/#', $url, $m) ? $m[1] : null;
    }

    /**
     * 写盘并检查返回值（磁盘 throw=false，必须显式检查）。
     */
    private function putChecked($disk, string $localPath, string $relative, string $cacheControl): void
    {
        if (! $disk->put($relative, (string) file_get_contents($localPath), ['CacheControl' => $cacheControl])) {
            throw new \RuntimeException("写入图片磁盘失败: {$relative}");
        }
    }
}
