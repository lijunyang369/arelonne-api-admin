<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为 product_variants 表添加 (product_id, color, size) 组合唯一索引，
     * 防止同一商品下出现重复的颜色 × 尺码组合。
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique(['product_id', 'color', 'size']);
        });
    }

    /**
     * 回滚组合唯一索引。
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'color', 'size']);
        });
    }
};
