<?php

namespace Tests\Unit;

use App\Support\ImageVariants;
use Tests\TestCase;

class ImageVariantsTest extends TestCase
{
    private function makeJpeg(int $w, int $h): string
    {
        $path = tempnam(sys_get_temp_dir(), 'src') . '.jpg';
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 50, 50));
        imagejpeg($img, $path);
        imagedestroy($img);

        return $path;
    }

    public function test_变体路径_由原图派生(): void
    {
        $this->assertSame(
            '/images/products/slug/pid/img-01_480.webp',
            ImageVariants::variantPath('/images/products/slug/pid/img-01.jpg', 480)
        );
        $this->assertSame('a_960.webp', ImageVariants::variantPath('a.jpeg', 960));
    }

    public function test_变体档位_恒返回全部三档(): void
    {
        $this->assertSame([480, 960, 1600], array_column(ImageVariants::variantsFor(), 'width'));
    }

    public function test_GD生成变体并等比缩放(): void
    {
        $src = $this->makeJpeg(1000, 1500);
        $dest = tempnam(sys_get_temp_dir(), 'dst') . '.webp';

        $this->assertTrue(ImageVariants::generate($src, $dest, 480));

        [$w, $h] = getimagesize($dest);
        $this->assertSame(480, $w);
        $this->assertSame(720, $h);

        @unlink($src);
        @unlink($dest);
    }

    public function test_窄图按原尺寸重编码到档位key(): void
    {
        // 原图 400 宽 → _480 档按 400 原尺寸输出（恒生成契约）
        $src = $this->makeJpeg(400, 600);
        $dest = tempnam(sys_get_temp_dir(), 'dst') . '.webp';

        $this->assertTrue(ImageVariants::generate($src, $dest, 480));

        [$w, $h] = getimagesize($dest);
        $this->assertSame(400, $w);
        $this->assertSame(600, $h);
        $this->assertSame(IMAGETYPE_WEBP, getimagesize($dest)[2]);

        @unlink($src);
        @unlink($dest);
    }

    public function test_损坏图片返回false(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($src, 'not an image');

        $this->assertFalse(ImageVariants::generate($src, sys_get_temp_dir() . '/x.webp', 480));

        @unlink($src);
    }

    public function test_极端宽高比不崩溃且目标高至少为1(): void
    {
        $src = $this->makeJpeg(8000, 1); // 8000x1
        $dest = tempnam(sys_get_temp_dir(), 'dst') . '.webp';

        // 480 档：目标高 round(1 * 480/8000) = 0 → 必须钳制为 1，不抛 ValueError
        $result = ImageVariants::generate($src, $dest, 480);

        $this->assertTrue($result);
        [$w, $h] = getimagesize($dest);
        $this->assertSame(480, $w);
        $this->assertSame(1, $h);

        @unlink($src);
        @unlink($dest);
    }

    public function test_超出像素上限返回false(): void
    {
        config()->set('image.max_pixels', 1000000); // 100 万像素上限
        $src = $this->makeJpeg(2000, 2000); // 400 万像素

        $this->assertFalse(ImageVariants::generate($src, sys_get_temp_dir() . '/x.webp', 480));

        @unlink($src);
    }

    /** 构造 3x2 非对称彩色网格 PNG（无损，采样可精确断言）：row0 [R,G,B] row1 [Y,C,M] */
    private function makeGrid(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'grid') . '.png';
        $img = imagecreatetruecolor(3, 2);
        $colors = [
            'R' => imagecolorallocate($img, 255, 0, 0),
            'G' => imagecolorallocate($img, 0, 255, 0),
            'B' => imagecolorallocate($img, 0, 0, 255),
            'Y' => imagecolorallocate($img, 255, 255, 0),
            'C' => imagecolorallocate($img, 0, 255, 255),
            'M' => imagecolorallocate($img, 255, 0, 255),
        ];
        $grid = [[$colors['R'], $colors['G'], $colors['B']], [$colors['Y'], $colors['C'], $colors['M']]];
        foreach ($grid as $y => $row) {
            foreach ($row as $x => $c) {
                imagesetpixel($img, $x, $y, $c);
            }
        }
        imagepng($img, $path);
        imagedestroy($img);

        return [$path, $colors];
    }

    public function test_EXIF方向1到8_旋正后四角像素符合标准(): void
    {
        [$path, $colors] = $this->makeGrid();

        // 每个 orientation 的期望四角（TL/TR/BL/BR），由 EXIF 标准语义独立推导
        // （2=flipH, 3=rot180, 4=flipV, 5=flipV+CW90, 6=CW90, 7=flipV+CCW90, 8=CCW90），
        // 不依赖实现代码，避免循环论证。
        $expected = [
            1 => ['R', 'B', 'Y', 'M'], // 恒等
            2 => ['B', 'R', 'M', 'Y'], // 水平翻转
            3 => ['M', 'Y', 'B', 'R'], // 180°
            4 => ['Y', 'M', 'R', 'B'], // 垂直翻转
            5 => ['R', 'Y', 'B', 'M'], // transpose
            6 => ['Y', 'R', 'M', 'B'], // 顺时针 90°
            7 => ['M', 'B', 'Y', 'R'], // transverse
            8 => ['B', 'M', 'R', 'Y'], // 逆时针 90°
        ];

        // 直接注入 orientation（exif_read_data 无法写 EXIF，测试走公开的 WithValue 方法）
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $orientation) {
            $image = imagecreatefrompng($path);
            $rotated = ImageVariants::applyExifOrientationWithValue($image, $orientation);

            $w = imagesx($rotated);
            $h = imagesy($rotated);

            // 1-4 保持 3x2；5-8 交换宽高为 2x3
            if (in_array($orientation, [1, 2, 3, 4], true)) {
                $this->assertSame(3, $w, "orientation {$orientation} 宽");
                $this->assertSame(2, $h, "orientation {$orientation} 高");
            } else {
                $this->assertSame(2, $w, "orientation {$orientation} 宽");
                $this->assertSame(3, $h, "orientation {$orientation} 高");
            }

            [$tl, $tr, $bl, $br] = $expected[$orientation];
            $this->assertSame($colors[$tl], imagecolorat($rotated, 0, 0), "orientation {$orientation} TL");
            $this->assertSame($colors[$tr], imagecolorat($rotated, $w - 1, 0), "orientation {$orientation} TR");
            $this->assertSame($colors[$bl], imagecolorat($rotated, 0, $h - 1), "orientation {$orientation} BL");
            $this->assertSame($colors[$br], imagecolorat($rotated, $w - 1, $h - 1), "orientation {$orientation} BR");

            imagedestroy($rotated);
        }

        @unlink($path);
    }

    public function test_输出产物为有效WebP(): void
    {
        $src = $this->makeJpeg(1000, 1000);
        $dest = tempnam(sys_get_temp_dir(), 'dst') . '.webp';

        ImageVariants::generate($src, $dest, 960);

        $info = getimagesize($dest);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertGreaterThan(0, filesize($dest));

        @unlink($src);
        @unlink($dest);
    }
}
