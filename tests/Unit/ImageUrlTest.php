<?php

namespace Tests\Unit;

use App\Http\Resources\ProductImageResource;
use App\Models\ProductImage;
use App\Support\ImageUrl;
use Tests\TestCase;

class ImageUrlTest extends TestCase
{
    public function test_基址为空时_输出相对路径(): void
    {
        config()->set('app.image_base_url', '');

        $this->assertSame('/images/products/a.jpg', ImageUrl::absolute('/images/products/a.jpg'));
    }

    public function test_基址配置时_拼接完整URL与缩略图(): void
    {
        config()->set('app.image_base_url', 'https://cdn.arelonne.com');

        $this->assertSame('https://cdn.arelonne.com/images/products/a.jpg', ImageUrl::absolute('/images/products/a.jpg'));
        $this->assertSame('https://cdn.arelonne.com/images/products/a_480.webp', ImageUrl::thumb('/images/products/a.jpg'));
    }

    public function test_绝对URL原样返回(): void
    {
        config()->set('app.image_base_url', 'https://cdn.arelonne.com');

        $this->assertSame('https://cdn.shopify.com/x.jpg', ImageUrl::absolute('https://cdn.shopify.com/x.jpg'));
        $this->assertSame('https://cdn.shopify.com/x.jpg', ImageUrl::thumb('https://cdn.shopify.com/x.jpg'));
    }

    public function test_空路径返回空(): void
    {
        $this->assertNull(ImageUrl::absolute(null));
        $this->assertNull(ImageUrl::thumb(null));
    }

    public function test_图片资源输出包含thumb_url(): void
    {
        config()->set('app.image_base_url', 'https://cdn.arelonne.com');

        $img = new ProductImage([
            'url'        => '/images/products/a.jpg',
            'alt'        => 'x',
            'sort'       => 0,
            'is_primary' => true,
        ]);

        $out = (new ProductImageResource($img))->resolve();

        $this->assertSame('https://cdn.arelonne.com/images/products/a.jpg', $out['url']);
        $this->assertSame('https://cdn.arelonne.com/images/products/a_480.webp', $out['thumb_url']);
    }

    public function test_商品资源输出skcs及其图片thumb(): void
    {
        config()->set('app.image_base_url', 'https://cdn.arelonne.com');

        $product = new \App\Models\Product(['id' => 1, 'slug' => 'p']);
        $skc = new \App\Models\ProductSkc(['id' => 10, 'color' => 'Black', 'slug' => 'p-black']);
        $skc->setRelation('images', collect([
            new ProductImage([
                'url'        => '/images/products/p/pid/p-black/img.jpg',
                'alt'        => 'x',
                'sort'       => 0,
                'is_primary' => true,
            ]),
        ]));
        $product->setRelation('skcs', collect([$skc]));

        // 嵌套 ResourceCollection 由 jsonSerialize 惰性解析，用 toJson 序列化后断言
        $out = json_decode((new \App\Http\Resources\ProductResource($product))->toJson(), true);

        $this->assertArrayHasKey('skcs', $out);
        $this->assertSame(
            'https://cdn.arelonne.com/images/products/p/pid/p-black/img_480.webp',
            $out['skcs'][0]['images'][0]['thumb_url']
        );
    }
}
