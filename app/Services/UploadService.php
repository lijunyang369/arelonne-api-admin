<?php

namespace App\Services;

use App\Models\Upload;
use App\Support\ImageVariants;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 运营图片上传：presign 直传 + confirm 落盘（幂等 + 并发安全 + 崩溃恢复）。
 *
 * 状态机契约（见 spec v2.1 §4）：
 * - 可纠正错误（对象未到、写入失败）→ 回 pending，可重试
 * - 确定性非法内容 → failed；重试稳定返回与首次相同的 422（幂等，不卡 409）
 * - processing 崩溃残留：processing_at 超 5 分钟被后续 confirm 抢占恢复
 * 唯一 key + immutable 缓存 → 不需要 CloudFront 失效。
 */
class UploadService
{
    /** 允许的上传类型（写入前缀白名单） */
    public const TYPES = ['banner', 'editorial', 'product-shot'];

    /** mime → 扩展名映射（扩展名只从此表推导，不信任文件名） */
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** 版本化资源缓存头 */
    private const IMMUTABLE = 'public, max-age=31536000, immutable';

    /** processing 崩溃残留的抢占超时（分钟） */
    private const PROCESSING_TIMEOUT_MINUTES = 5;

    /**
     * 签发直传：建记录 + 返回待确认 key 与上传 URL。
     *
     * @return array{key: string, upload_url: string, upload_headers: array, auth_required: bool, expires_in: int}
     */
    public function presign(string $type, string $filename, string $mime, int $size): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => '不支持的上传类型。']);
        }
        if ($size > (int) config('image.max_upload_bytes', 15728640)) {
            throw ValidationException::withMessages(['size' => '文件不能超过 15MB。']);
        }
        $ext = self::MIME_EXT[$mime] ?? null;
        if ($ext === null) {
            throw ValidationException::withMessages(['mime' => '仅支持 jpeg/png/webp。']);
        }
        $declaredExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedExts = $mime === 'image/jpeg' ? ['jpg', 'jpeg'] : [$ext];
        if (! in_array($declaredExt, $allowedExts, true)) {
            throw ValidationException::withMessages(['filename' => '文件名扩展名与 mime 不匹配。']);
        }

        $key = sprintf('uploads/_pending/%s/%s.%s', $type, Str::uuid(), $ext);

        Upload::create([
            'key'    => $key,
            'type'   => $type,
            'mime'   => $mime,
            'size'   => $size,
            'status' => 'pending',
        ]);

        if (config('filesystems.disks.image.driver') === 's3') {
            // temporaryUploadUrl 返回 ['url' => ..., 'headers' => ...]，必须解构
            $temporary = Storage::disk('image')->temporaryUploadUrl($key, now()->addMinutes(10), [
                'ContentType' => $mime,
            ]);

            // 浏览器 PUT 不可设置 Host/Content-Length 等禁止头；只透传可设置的签名头并展平数组值
            $headers = [];
            foreach (($temporary['headers'] ?? []) as $name => $value) {
                $lower = strtolower((string) $name);
                if (in_array($lower, ['host', 'content-length'], true) || str_starts_with($lower, 'x-amz-content-sha256')) {
                    continue;
                }
                $headers[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
            // Content-Type 由前端 PUT 时固定携带（契约），这里确保契约字段存在
            $headers['Content-Type'] = $mime;

            return [
                'key'            => $key,
                'upload_url'     => $temporary['url'],
                'upload_headers' => $headers,
                'auth_required'  => false,
                'expires_in'     => 600,
            ];
        }

        // dev（local 盘不支持签名 URL）：Laravel signed route，真实 10 分钟过期；需 Bearer
        return [
            'key'            => $key,
            'upload_url'     => URL::temporarySignedRoute('admin.uploads.dev-put', now()->addMinutes(10), ['key' => $key]),
            'upload_headers' => (object) [],
            'auth_required'  => true,
            'expires_in'     => 600,
        ];
    }

    /**
     * 确认上传（幂等 + 并发安全 + 崩溃恢复）。
     *
     * @return array{url: string, thumb_url: string, width: int, height: int}
     */
    public function confirm(string $key): array
    {
        $upload = Upload::where('key', $key)->first();
        if (! $upload) {
            throw ValidationException::withMessages(['key' => '上传记录不存在。']);
        }

        // 已确认：直接返回（重试幂等）
        if ($upload->status === 'confirmed') {
            return $this->resultOf($upload);
        }

        // 确定性非法：重试稳定返回原 422（幂等，不卡 409）
        if ($upload->status === 'failed') {
            throw ValidationException::withMessages(['key' => $upload->error ?? '内容校验失败。']);
        }

        // CAS 抢占（pending 或超时 processing 可被抢占，见 claimProcessing）
        if (! $this->claimProcessing($upload)) {
            abort(409, '该上传正在处理中，请稍后重试。');
        }

        $disk = Storage::disk('image');
        $tmp = null;
        $written = [];
        $recoverable = false; // 可纠正错误标记（对象未到达 → 回 pending，不进 failed 分支）

        try {
            // 校验顺序（先省内存）：size 比对 → 下载 → getimagesize → finfo
            if (! $disk->exists($key)) {
                // 可纠正错误：对象可能尚未写入/最终一致不可见 → 回 pending 可重试（spec 状态机契约）
                // 用查询构造器更新（claimProcessing 是查询级更新，模型实例的 status 内存值陈旧，
                // 且同值属性不产生 dirty，模型 update() 不会写回 status）
                $recoverable = true;
                Upload::whereKey($upload->id)->update(['status' => 'pending', 'processing_at' => null, 'error' => '上传对象尚未到达']);
                throw ValidationException::withMessages(['key' => '上传对象尚未到达，请稍后重试。']);
            }

            $actualSize = (int) $disk->size($key);
            if ($actualSize <= 0 || $actualSize !== (int) $upload->size || $actualSize > (int) config('image.max_upload_bytes', 15728640)) {
                throw ValidationException::withMessages(['key' => '文件大小与声明不符或超限。']);
            }

            $tmp = tempnam(sys_get_temp_dir(), 'upload');
            file_put_contents($tmp, (string) $disk->get($key));

            // 解码前校验宽高/像素上限（含高度上限）
            $size = @getimagesize($tmp);
            if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
                throw ValidationException::withMessages(['key' => '文件不是有效图片。']);
            }
            $maxDimension = (int) config('image.max_dimension', 8000);
            $maxPixels = (int) config('image.max_pixels', 24000000);
            if ($size[0] > $maxDimension || $size[1] > $maxDimension || $size[0] * $size[1] > $maxPixels) {
                throw ValidationException::withMessages(['key' => '图片尺寸超出限制。']);
            }

            // 真实 MIME 与声明、扩展三者一致
            $realMime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: null;
            $expectedExt = self::MIME_EXT[$upload->mime] ?? null;
            $keyExt = strtolower(pathinfo($key, PATHINFO_EXTENSION));
            if ($realMime !== $upload->mime || $keyExt !== $expectedExt) {
                throw ValidationException::withMessages(['key' => '文件内容与声明类型不符。']);
            }

            [$width, $height] = $size;

            // 正式路径：uploads/{type}/{created_at Ymd}/{uuid}.{ext}（基于记录，跨午夜重试稳定）
            $final = sprintf('uploads/%s/%s/%s', $upload->type, $upload->created_at->format('Ymd'), basename($key));

            if (! $disk->put($final, (string) file_get_contents($tmp), ['CacheControl' => self::IMMUTABLE])) {
                throw new \RuntimeException("写入原图失败: {$final}");
            }
            $written[] = $final;

            foreach (ImageVariants::variantsFor() as $variant) {
                $variantPath = ImageVariants::variantPath($final, $variant['width']);
                $variantTmpBase = tempnam(sys_get_temp_dir(), 'var');
                $variantTmp = $variantTmpBase . '.webp';
                @unlink($variantTmpBase);

                if (! ImageVariants::generate($tmp, $variantTmp, $variant['width'])) {
                    @unlink($variantTmp);
                    throw new \RuntimeException("生成变体失败: {$variantPath}");
                }
                if (! $disk->put($variantPath, (string) file_get_contents($variantTmp), ['CacheControl' => self::IMMUTABLE])) {
                    @unlink($variantTmp);
                    throw new \RuntimeException("写入变体失败: {$variantPath}");
                }
                @unlink($variantTmp);
                $written[] = $variantPath;
            }

            $upload->update([
                'status'      => 'confirmed',
                'processing_at' => null,
                'final_path'  => "/{$final}",
                'thumb_path'  => '/' . ImageVariants::variantPath($final, (int) (config('image.widths')[0] ?? 480)),
                'width'       => $width,
                'height'      => $height,
                'error'       => null,
            ]);

            // 删除待确认对象（失败仅告警，不影响响应）
            if (! $disk->delete($key)) {
                Log::warning("删除 pending 对象失败: {$key}");
            }

            return $this->resultOf($upload->refresh());
        } catch (ValidationException $e) {
            // 确定性非法：failed + 记录错误（重试稳定返回原 422）；pending 对象保留。
            // 可纠正错误（对象未到达）分支已回 pending，不再被覆写为 failed（spec 状态机契约）
            if (! $recoverable) {
                $upload->update(['status' => 'failed', 'processing_at' => null, 'error' => $e->getMessage()]);
            }
            throw $e;
        } catch (\Throwable $e) {
            // 运行类错误：回滚本次已写对象、回 pending（保留 pending 源对象）
            foreach ($written as $p) {
                try {
                    if (! $disk->delete($p)) {
                        Log::warning("回滚删除失败（返回 false）: {$p}");
                    }
                } catch (\Throwable) {}
            }
            $upload->update(['status' => 'pending', 'processing_at' => null, 'error' => $e->getMessage()]);
            Log::error('确认上传失败: ' . $e->getMessage(), ['key' => $key]);
            abort(500, '确认上传失败，请重试。');
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    /**
     * CAS 抢占 processing：pending 或超时（5 分钟）的 processing 可被抢占。
     * 成功返回 true 并写入 processing_at=now()（注意 Carbon 不可原地 subMinutes）。
     *
     * @return bool 抢占成功与否
     */
    public function claimProcessing(Upload $upload): bool
    {
        $now = now();
        $threshold = $now->copy()->subMinutes(self::PROCESSING_TIMEOUT_MINUTES);

        $claimed = Upload::whereKey($upload->id)
            ->where(function ($q) use ($threshold) {
                $q->where('status', 'pending')
                    ->orWhere(function ($qq) use ($threshold) {
                        $qq->where('status', 'processing')
                            ->where('processing_at', '<', $threshold);
                    });
            })
            ->update(['status' => 'processing', 'processing_at' => $now]);

        return $claimed === 1;
    }

    /**
     * dev 专用直传（signed URL + 配置开关 + Sanctum）：原始字节写入待确认 key。
     */
    public function devPut(string $key, string $body): void
    {
        abort_unless(
            (bool) config('image.dev_put_enabled', false)
                && config('filesystems.disks.image.driver') === 'local',
            404
        );

        if (strlen($body) === 0 || strlen($body) > (int) config('image.max_upload_bytes', 15728640)) {
            abort(422, '空文件或超过 15MB。');
        }
        if (! Upload::where('key', $key)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['key' => '上传记录不存在或状态不符。']);
        }

        if (! Storage::disk('image')->put($key, $body)) {
            abort(500, '写入待确认对象失败。');
        }
    }

    /**
     * 由记录构建响应。
     *
     * @return array{url: string, thumb_url: string, width: int, height: int}
     */
    private function resultOf(Upload $upload): array
    {
        return [
            'url'       => $upload->final_path,
            'thumb_url' => $upload->thumb_path,
            'width'     => (int) $upload->width,
            'height'    => (int) $upload->height,
        ];
    }
}
