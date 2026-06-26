<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * 创建测试商品数据：2 个分类 + 4 个商品 + 变体。
     * 用于验证前端商品列表/详情 API。
     */
    public function run(): void
    {
        // ---- 分类 ----
        $bras = Category::create([
            'name' => 'Bras & Innerwear', 'slug' => 'bras', 'sort' => 1, 'status' => 'active',
        ]);
        $linen = Category::create([
            'name' => 'Cotton & Linen', 'slug' => 'linen', 'sort' => 2, 'status' => 'active',
        ]);

        // ---- 商品 1: Bra ----
        $p1 = Product::create([
            'name' => 'CloudSoft Wireless Bra',
            'slug' => 'cloudsoft-wireless-bra',
            'description' => 'A wire-free bra that feels like you\'re wearing nothing. Breathable cotton-modal blend with gentle support for all-day comfort.',
            'category_id' => $bras->id,
            'base_price' => 49.00,
            'cost_price' => 12.00,
            'status' => 'active',
            'sort' => 1,
            'meta' => ['fabric' => 'Cotton-Modal Blend', 'care' => 'Machine wash cold, lay flat to dry'],
        ]);
        $this->addVariants($p1, 'BRA', ['Black', 'Nude', 'Dusty Rose'], ['S', 'M', 'L', 'XL']);
        $this->addImage($p1, 'https://picsum.photos/seed/bra1/600/800', 'CloudSoft Bra — Front', true);
        $this->addImage($p1, 'https://picsum.photos/seed/bra2/600/800', 'CloudSoft Bra — Side');

        // ---- 商品 2: Linen Shirt ----
        $p2 = Product::create([
            'name' => 'Relaxed Linen Shirt',
            'slug' => 'relaxed-linen-shirt',
            'description' => 'A lightweight, relaxed-fit linen shirt that breathes in warm weather. Perfect for layering or wearing on its own.',
            'category_id' => $linen->id,
            'base_price' => 68.00,
            'cost_price' => 18.00,
            'status' => 'active',
            'sort' => 2,
            'meta' => ['fabric' => '100% French Linen', 'care' => 'Machine wash gentle, tumble dry low'],
        ]);
        $this->addVariants($p2, 'LIN', ['White', 'Natural', 'Sage Green'], ['S', 'M', 'L']);
        $this->addImage($p2, 'https://picsum.photos/seed/linen1/600/800', 'Linen Shirt — Front', true);

        // ---- 商品 3: Cotton Tank ----
        $p3 = Product::create([
            'name' => 'Essential Cotton Tank',
            'slug' => 'essential-cotton-tank',
            'description' => 'The perfect layering piece. Soft, stretchy organic cotton with a flattering fit. Wear it under anything or on its own.',
            'category_id' => $bras->id,
            'base_price' => 35.00,
            'cost_price' => 8.00,
            'status' => 'active',
            'sort' => 3,
            'meta' => ['fabric' => 'Organic Cotton', 'care' => 'Machine wash cold'],
        ]);
        $this->addVariants($p3, 'TNK', ['White', 'Black', 'Grey'], ['XS', 'S', 'M', 'L']);
        $this->addImage($p3, 'https://picsum.photos/seed/tank1/600/800', 'Cotton Tank — Front', true);

        // ---- 商品 4: Linen Dress ----
        $p4 = Product::create([
            'name' => 'Breezy Linen Midi Dress',
            'slug' => 'breezy-linen-midi-dress',
            'description' => 'An effortless midi dress in pure linen. Side pockets, relaxed silhouette — made for sunny days and weekend getaways.',
            'category_id' => $linen->id,
            'base_price' => 89.00,
            'sale_price' => 79.00,
            'cost_price' => 22.00,
            'status' => 'active',
            'sort' => 4,
            'meta' => ['fabric' => '100% European Linen', 'care' => 'Hand wash or dry clean'],
        ]);
        $this->addVariants($p4, 'LDR', ['White', 'Navy', 'Terracotta'], ['S', 'M', 'L']);
        $this->addImage($p4, 'https://picsum.photos/seed/dress1/600/800', 'Linen Dress — Front', true);

        $this->command?->info('✅ 4 test products seeded with variants and images.');
    }

    /**
     * 为商品批量创建变体。
     */
    private function addVariants(Product $product, string $prefix, array $colors, array $sizes): void
    {
        foreach ($colors as $color) {
            foreach ($sizes as $size) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'sku'        => "HLP-{$prefix}-{$color}-{$size}",
                    'color'      => $color,
                    'size'       => $size,
                    'price'      => null,  // 使用 product base_price
                    'stock'      => rand(5, 50),
                ]);
            }
        }
    }

    /**
     * 为商品添加一张图片。
     */
    private function addImage(Product $product, string $url, string $alt = '', bool $isPrimary = false): void
    {
        static $sort = 0;
        ProductImage::create([
            'product_id' => $product->id,
            'url'        => $url,
            'alt'        => $alt,
            'sort'       => $sort++,
            'is_primary' => $isPrimary,
        ]);
    }
}
