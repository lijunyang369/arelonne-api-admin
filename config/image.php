<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 图片管线配置
    |--------------------------------------------------------------------------
    |
    | 变体命名约定（唯一事实来源，见 docs/architecture 图片存储一节）：
    |   原图 a.jpg → 变体 a_480.webp / a_960.webp / a_1600.webp，同目录，恒生成三档。
    |
    */

    // 导入管线暂存区根目录（测试覆盖为独立临时目录，禁止测试动真实 staging）
    'staging_root' => storage_path('app/staging'),

    // 变体档位（像素），与 web-store image-loader.ts 的 WIDTHS 保持一致
    'widths' => [480, 960, 1600],

    // WebP 编码质量
    'quality' => 80,

    // 图片解码上限（防 GD 内存耗尽）
    'max_dimension' => 8000,
    'max_pixels' => 24000000, // 2400 万像素

    // 上传大小上限（字节，15MB）
    'max_upload_bytes' => 15728640,

    // dev 直传端点开关：默认仅 APP_ENV=local 开启；PHPUnit（testing）显式开启
    'dev_put_enabled' => env('IMAGE_DEV_PUT_ENABLED', env('APP_ENV', 'production') === 'local'),
];
