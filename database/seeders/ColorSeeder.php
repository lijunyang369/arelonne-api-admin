<?php

namespace Database\Seeders;

use App\Models\Color;
use Illuminate\Database\Seeder;

class ColorSeeder extends Seeder
{
    /**
     * 品牌颜色调色板 — 含中英文名称。
     */
    public function run(): void
    {
        $colors = [
            // 基础色
            ['name' => 'Black',          'name_zh' => '黑色',       'hex' => '#2D2A26'],
            ['name' => 'White',          'name_zh' => '白色',       'hex' => '#F5F0E8'],
            ['name' => 'Pure White',     'name_zh' => '纯白',       'hex' => '#FAF9F8'],
            ['name' => 'Off White',      'name_zh' => '米白',       'hex' => '#F8F5F0'],
            ['name' => 'Natural White',  'name_zh' => '自然白',     'hex' => '#F7F3EC'],
            ['name' => 'Natural',        'name_zh' => '自然色',     'hex' => '#E8E2D5'],
            ['name' => 'Nude',           'name_zh' => '裸色',       'hex' => '#E8D5C0'],
            ['name' => 'Ecru',           'name_zh' => '本白',       'hex' => '#E8DCC8'],
            ['name' => 'Light Grey',     'name_zh' => '浅灰',       'hex' => '#D8D4D0'],
            ['name' => 'Moon Rock',      'name_zh' => '月岩灰',     'hex' => '#B8B0A8'],
            ['name' => 'Fog',            'name_zh' => '雾灰',       'hex' => '#C8C4C0'],
            ['name' => 'Warm Sand',      'name_zh' => '暖沙色',     'hex' => '#D4C5B9'],
            ['name' => 'Desert Sand Beige', 'name_zh' => '沙漠米色','hex' => '#D8C8B0'],

            // 蓝色系
            ['name' => 'Navy',           'name_zh' => '海军蓝',     'hex' => '#2C3E5A'],
            ['name' => 'Universe Navy',  'name_zh' => '宇宙海军蓝', 'hex' => '#2C3E5A'],
            ['name' => 'Midnight Navy',  'name_zh' => '午夜海军蓝', 'hex' => '#1C2E4A'],
            ['name' => 'Smoky Navy',     'name_zh' => '烟熏海军蓝', 'hex' => '#3C4A5A'],
            ['name' => 'Denim Navy',     'name_zh' => '牛仔海军蓝', 'hex' => '#3A5068'],
            ['name' => 'Davy',           'name_zh' => '灰蓝',       'hex' => '#5A6B72'],
            ['name' => 'Dark Marine',    'name_zh' => '深海蓝',     'hex' => '#2E4A52'],
            ['name' => 'Dusty Blue',     'name_zh' => '雾蓝',       'hex' => '#8FA0B4'],
            ['name' => 'Ice Blue',       'name_zh' => '冰蓝',       'hex' => '#B8D8E8'],
            ['name' => 'Light Azure Blue', 'name_zh' => '浅天蓝',   'hex' => '#A0C4D8'],
            ['name' => 'Arctic Mist Blue', 'name_zh' => '北极雾蓝', 'hex' => '#C8D8E8'],

            // 绿色系
            ['name' => 'Sage Green',     'name_zh' => '鼠尾草绿',   'hex' => '#B2BCA0'],
            ['name' => 'Olive Spring Green', 'name_zh' => '橄榄春绿','hex' => '#8B9467'],
            ['name' => 'Sprout Green',   'name_zh' => '嫩芽绿',     'hex' => '#9CB870'],

            // 红色/粉色系
            ['name' => 'Scarlet Red',    'name_zh' => '猩红',       'hex' => '#E23D3D'],
            ['name' => 'Ruby Red',       'name_zh' => '宝石红',     'hex' => '#C41E3A'],
            ['name' => 'Red',            'name_zh' => '红色',       'hex' => '#D42020'],
            ['name' => 'Rust',           'name_zh' => '铁锈红',     'hex' => '#B85C3A'],
            ['name' => 'Deep Fig',       'name_zh' => '深无花果紫', 'hex' => '#5C3A4A'],
            ['name' => 'Powder Pink',    'name_zh' => '粉玫',       'hex' => '#E8C8D0'],
            ['name' => 'Primrose Pink',  'name_zh' => '樱草粉',     'hex' => '#E8B4C0'],
            ['name' => 'Soft Pink',      'name_zh' => '柔粉',       'hex' => '#E8C4CC'],

            // 紫色系
            ['name' => 'Lilac Mist Purple', 'name_zh' => '丁香雾紫', 'hex' => '#C4B5D0'],
            ['name' => 'Purple Fog',     'name_zh' => '紫雾',       'hex' => '#9B8EA8'],

            // 黄色/金色系
            ['name' => 'Cream Yellow',   'name_zh' => '奶油黄',     'hex' => '#F5E6B8'],
            ['name' => 'Butter Yellow',  'name_zh' => '黄油黄',     'hex' => '#F5E898'],
            ['name' => 'Antique Gold',   'name_zh' => '古铜金',     'hex' => '#C8A860'],
            ['name' => 'Iced Mocha',     'name_zh' => '冰摩卡',     'hex' => '#C4A882'],

            // 特殊
            ['name' => 'Christmas Special', 'name_zh' => '圣诞限定', 'hex' => '#C41E3A'],
        ];

        foreach ($colors as $color) {
            Color::updateOrCreate(
                ['name' => $color['name']],
                $color,
            );
        }

        $this->command?->info('✅ ' . count($colors) . ' colors seeded with Chinese names.');
    }
}
