<?php

namespace App\Support;

/**
 * 图片 URL 拼装：CDN 基址 + 相对路径。
 *
 * dev（APP_IMAGE_BASE_URL 为空）输出历史相对路径，行为不变。
 */
class ImageUrl
{
    /**
     * 相对路径 → 完整 URL（含 CDN 基址）。绝对 URL 原样返回。
     */
    public static function absolute(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return $path;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('app.image_base_url'), '/') . '/' . ltrim($path, '/');
    }

    /**
     * 相对路径 → 480 缩略图完整 URL（三档恒生成，key 恒存在）。
     */
    public static function thumb(?string $path): ?string
    {
        if ($path === null || $path === '' || str_starts_with($path, 'http')) {
            return self::absolute($path);
        }

        return self::absolute(ImageVariants::variantPath($path, 480));
    }
}
