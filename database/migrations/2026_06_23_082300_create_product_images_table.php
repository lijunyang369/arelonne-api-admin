<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建商品图片表（每个 SKU 可有多张图）。
     */
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url');                          // 图片 URL / 路径
            $table->string('alt')->nullable();              // alt 文本
            $table->unsignedInteger('sort')->default(0);    // 排序
            $table->boolean('is_primary')->default(false);  // 是否主图
            $table->timestamps();

            $table->index('product_id');
            $table->index('product_variant_id');
        });
    }

    /**
     * 回滚图片表。
     */
    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
