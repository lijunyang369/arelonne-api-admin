<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建品牌颜色调色板表。
     * 颜色通过名称与 product_variants.color 关联，前端通过 API 获取 hex 映射。
     */
    public function up(): void
    {
        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();              // 颜色名，如 "Scarlet Red"
            $table->string('hex', 7);                      // 色值，如 "#E23D3D"
            $table->string('status')->default('active');   // active / inactive
            $table->timestamps();

            $table->index('name');
        });
    }

    /**
     * 回滚颜色表。
     */
    public function down(): void
    {
        Schema::dropIfExists('colors');
    }
};
