<?php

namespace Tests\Unit;

use Tests\TestCase;

class ImageConfigTest extends TestCase
{
    public function test_image_磁盘默认_local_指向_web_store_public(): void
    {
        $this->assertSame('local', config('filesystems.disks.image.driver'));
        $this->assertStringEndsWith('/web-store/public', (string) config('filesystems.disks.image.root'));
    }

    public function test_image_配置默认值(): void
    {
        $this->assertStringEndsWith('/storage/app/staging', (string) config('image.staging_root'));
        $this->assertSame([480, 960, 1600], config('image.widths'));
        $this->assertSame(80, config('image.quality'));
        $this->assertSame(8000, config('image.max_dimension'));
        $this->assertSame(24000000, config('image.max_pixels'));
        $this->assertSame(15728640, config('image.max_upload_bytes'));
        $this->assertFalse(config('image.dev_put_enabled')); // testing 环境默认关闭
    }

    public function test_图片基址与cloudfront_默认空字符串(): void
    {
        $this->assertSame('', config('app.image_base_url'));
        $this->assertSame('', config('app.cloudfront_distribution_id'));
    }
}
