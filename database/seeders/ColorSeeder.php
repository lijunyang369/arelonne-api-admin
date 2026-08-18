<?php

namespace Database\Seeders;

use App\Models\Color;
use Illuminate\Database\Seeder;

class ColorSeeder extends Seeder
{
    /**
     * 品牌颜色调色板 — 上线只保留三色（2026-08-18 定稿）：
     * Midnight Navy / Black / Pure White。
     * 执行时收敛：三色之外的旧颜色一律删除。
     *
     * 种子数据唯一归属：共享库的 seeders 由 api-admin 维护，api-store 不持有 seeders。
     */
    public function run(): void
    {
        $colors = [
            ['name' => 'Midnight Navy', 'name_zh' => '午夜海军蓝', 'hex' => '#1C2E4A'],
            ['name' => 'Black',         'name_zh' => '黑色',       'hex' => '#2D2A26'],
            ['name' => 'Pure White',    'name_zh' => '纯白',       'hex' => '#FAF9F8'],
        ];

        foreach ($colors as $color) {
            Color::updateOrCreate(
                ['name' => $color['name']],
                $color,
            );
        }

        // 收敛：删除三色以外的旧色板数据
        Color::whereNotIn('name', array_column($colors, 'name'))->delete();

        $this->command?->info('✅ ' . count($colors) . ' colors seeded with Chinese names.');
    }
}
