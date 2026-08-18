<?php

namespace App\Console\Commands;

use App\Services\CloudFrontInvalidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * 白名单同步 web-store/public 静态资源到图片磁盘（生产 = S3）。
 *
 * 白名单条目唯一来源：web-store/scripts/cdn-static-manifest.json。
 * 只推白名单，永不整盘同步（防止误删 images/products——商品图由 api-admin 管线独占）。
 * 覆盖式资源（s-maxage 30 天 + max-age 1 小时），完成后对目录条目失效前缀。
 *
 * 用法：
 *   php artisan storage:sync-public-to-s3
 *   php artisan storage:sync-public-to-s3 --dry-run
 */
class StorageSyncPublicToS3 extends Command
{
    protected $signature = 'storage:sync-public-to-s3
                            {publicRoot? : public 目录（默认 web-store/public，测试可覆盖）}
                            {--manifest= : manifest 文件路径（默认 web-store/scripts/cdn-static-manifest.json，测试覆盖）}
                            {--dry-run : 只列出文件不写入}';

    protected $description = '白名单同步 web-store/public 静态资源到图片磁盘';

    /** 覆盖式资源缓存头（CDN 长缓存 + 浏览器短缓存，配合部署后失效） */
    private const STATIC_CACHE = 'public, max-age=3600, s-maxage=2592000';

    /** manifest 相对路径 */
    private const MANIFEST_PATH = '../web-store/scripts/cdn-static-manifest.json';

    /**
     * 执行同步。
     */
    public function handle(CloudFrontInvalidator $invalidator): int
    {
        $publicRoot = $this->argument('publicRoot') ?? base_path('../web-store/public');
        $dryRun = (bool) $this->option('dry-run');

        // --manifest 选项优先（测试覆盖），否则读默认 manifest
        $entries = $this->loadManifest($this->option('manifest') ?: base_path(self::MANIFEST_PATH));
        if ($entries === null) {
            return self::FAILURE; // manifest 缺失/非法/为空 → 部署门禁失败
        }

        $count = 0;
        $errors = 0;
        $invalidPaths = [];

        foreach ($entries as $entry) {
            // 强排除（fail-closed）：商品图由 api-admin 管线独占，manifest 误含 = 错误
            if (str_starts_with($entry, 'images/products')) {
                $this->error("  拒绝同步（管线独占）: {$entry}");
                $errors++;
                continue;
            }

            $path = "{$publicRoot}/{$entry}";

            if (is_file($path)) {
                $this->line("  {$entry}");
                if ($dryRun) {
                    $count++;
                } elseif (Storage::disk('image')->put($entry, (string) file_get_contents($path), ['CacheControl' => self::STATIC_CACHE])) {
                    $count++;
                } else {
                    $this->error("  写入失败: {$entry}");
                    $errors++;
                }
                $invalidPaths[] = "/{$entry}";
            } elseif (is_dir($path)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    $rel = substr($file->getPathname(), strlen($publicRoot) + 1);
                    $this->line("  {$rel}");
                    if ($dryRun) {
                        $count++;
                    } elseif (Storage::disk('image')->put($rel, (string) file_get_contents($file->getPathname()), ['CacheControl' => self::STATIC_CACHE])) {
                        $count++;
                    } else {
                        $this->error("  写入失败: {$rel}");
                        $errors++;
                    }
                }
                $invalidPaths[] = "/{$entry}/*";
            } else {
                $this->warn("  跳过不存在: {$entry}");
            }
        }

        if ($errors > 0) {
            $this->error("同步失败：{$errors} 个错误（不执行失效）。");
            return self::FAILURE;
        }

        // 全部成功后才失效（避免失效未成功上传的路径）
        if (! $dryRun) {
            $invalidator->invalidate($invalidPaths);
        }

        $this->info($dryRun ? "DRY-RUN：{$count} 个文件。" : "已同步 {$count} 个文件。");

        return self::SUCCESS;
    }

    /**
     * 读取白名单 manifest。缺失/非法/为空返回 null（fail-closed）。
     *
     * @return array<int, string>|null
     */
    private function loadManifest(?string $path): ?array
    {
        $path ??= base_path(self::MANIFEST_PATH);

        if (! file_exists($path)) {
            $this->error("白名单 manifest 不存在: {$path}");
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $entries = is_array($data['entries'] ?? null) ? $data['entries'] : null;

        if ($entries === null || $entries === []) {
            $this->error("白名单 manifest 非法或为空: {$path}");
            return null;
        }

        return $entries;
    }
}
