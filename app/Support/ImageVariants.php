<?php

namespace App\Support;

/**
 * 图片变体：固定三档宽度 + WebP 输出（恒生成三档）。
 *
 * 命名约定（与 web-store loader 共享的唯一事实来源，见 docs/architecture 图片存储一节）：
 *   原图 a.jpg → 变体 a_480.webp / a_960.webp / a_1600.webp，同目录。
 *
 * 恒生成契约：原图宽度小于等于档位时按原尺寸重编码到对应 key，
 * 保证任意档位 key 恒存在，前端 loader/Resource 派生永不 404。
 */
class ImageVariants
{
    /** 允许的图片 mime 白名单（上传/导入共用） */
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * 由原图路径派生变体路径：a.jpg → a_480.webp。
     * 纯字符串操作，对绝对路径与相对路径都成立。
     */
    public static function variantPath(string $path, int $width): string
    {
        $info = pathinfo($path);

        return $info['dirname'] === '.'
            ? "{$info['filename']}_{$width}.webp"
            : "{$info['dirname']}/{$info['filename']}_{$width}.webp";
    }

    /**
     * 变体档位清单（恒为全部档位）。
     *
     * @return array<int, array{width: int, suffix: string}>
     */
    public static function variantsFor(): array
    {
        return collect((array) config('image.widths', [480, 960, 1600]))
            ->map(fn (int $width) => ['width' => $width, 'suffix' => "_{$width}"])
            ->values()
            ->all();
    }

    /**
     * 用 GD 生成单个 WebP 变体。
     *
     * - 恒生成：原图宽 ≤ 目标宽时按原尺寸重编码（不放大）
     * - 读取 EXIF Orientation 1–8 并旋正
     * - 校验最大边长/像素数，超出返回 false
     * - 输出到临时文件，校验为有效 WebP 后原子 rename
     *
     * @return bool 失败返回 false；调用方按"整图拒绝"处理
     */
    public static function generate(string $sourcePath, string $destPath, int $width, ?int $quality = null): bool
    {
        $quality ??= (int) config('image.quality', 80);

        // 解码前先校验（防止超大/超宽高比图耗尽内存，不分配 GD 资源）：
        // 1. 源文件字节数上限（变体生成接受比上传略宽松：2 倍上传上限）
        // 2. getimagesize 宽高/像素上限
        $maxPixels = (int) config('image.max_pixels', 24000000);
        $maxDimension = (int) config('image.max_dimension', 8000);
        $maxBytes = (int) config('image.max_upload_bytes', 15728640) * 2;

        if (! is_file($sourcePath) || filesize($sourcePath) === 0 || filesize($sourcePath) > $maxBytes) {
            return false;
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return false;
        }
        [$srcW, $srcH] = [$info[0], $info[1]];
        if ($srcW <= 0 || $srcH <= 0 || $srcW > $maxDimension || $srcH > $maxDimension || $srcW * $srcH > $maxPixels) {
            return false;
        }

        $source = @imagecreatefromstring((string) file_get_contents($sourcePath));
        if ($source === false) {
            return false;
        }

        // EXIF 方向旋正
        $source = self::applyExifOrientation($source, $sourcePath);

        $rotatedW = imagesx($source);
        $rotatedH = imagesy($source);

        $targetW = min($width, $rotatedW); // 恒生成：窄图按原尺寸重编码
        $targetH = max(1, (int) round($rotatedH * ($targetW / $rotatedW)));

        $dest = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        // 显式填充全透明画布，防未初始化像素
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefill($dest, 0, 0, $transparent);

        imagecopyresampled($dest, $source, 0, 0, 0, 0, $targetW, $targetH, $rotatedW, $rotatedH);
        imagedestroy($source);

        $dir = dirname($destPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // 先写临时文件，校验后再原子 rename
        $tmp = $destPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        try {
            $ok = imagewebp($dest, $tmp, $quality);
        } catch (\Throwable $e) {
            $ok = false;
        }
        imagedestroy($dest);

        if (! $ok || ! is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            return false;
        }

        $info = @getimagesize($tmp);
        if ($info === false || $info[2] !== IMAGETYPE_WEBP) {
            @unlink($tmp);
            return false;
        }

        if (! @rename($tmp, $destPath)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * 按 EXIF Orientation（1–8）旋正图片，返回处理后的 GD 资源。
     * 映射表与 Intervention Image 一致：
     *   2=flipH, 3=rotate180, 4=flipV, 5=flipV+CW90, 6=CW90, 7=flipV+CCW90, 8=CCW90
     */
    public static function applyExifOrientation(\GdImage $image, string $sourcePath): \GdImage
    {
        $orientation = 1;

        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath);
            $orientation = (int) ($exif['Orientation'] ?? 1);
        }

        return self::applyExifOrientationWithValue($image, $orientation);
    }

    /**
     * 按给定 orientation 值旋正（供测试直接注入，无需真实 EXIF 文件）。
     */
    public static function applyExifOrientationWithValue(\GdImage $image, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                imageflip($image, IMG_FLIP_BOTH);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                imageflip($image, IMG_FLIP_VERTICAL);
                $image = self::rotateCw($image);
                break;
            case 6:
                $image = self::rotateCw($image);
                break;
            case 7:
                imageflip($image, IMG_FLIP_VERTICAL);
                $image = self::rotateCcw($image);
                break;
            case 8:
                $image = self::rotateCcw($image);
                break;
        }

        return $image;
    }

    /**
     * 顺时针旋转 90°（保留 alpha）。
     */
    private static function rotateCw(\GdImage $image): \GdImage
    {
        $rotated = imagerotate($image, -90, 0);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    /**
     * 逆时针旋转 90°（保留 alpha）。
     */
    private static function rotateCcw(\GdImage $image): \GdImage
    {
        $rotated = imagerotate($image, 90, 0);
        imagesavealpha($rotated, true);

        return $rotated;
    }
}
