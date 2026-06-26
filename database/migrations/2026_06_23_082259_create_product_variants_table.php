<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建商品 SKU / 变体表（如颜色 × 尺码组合）。
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();                // SKU 编码
            $table->string('color')->nullable();             // 颜色
            $table->string('size')->nullable();              // 尺码（S/M/L/XL 或 数字）
            $table->decimal('price', 10, 2)->nullable();     // 变体单独定价（null=使用 product base_price）
            $table->unsignedInteger('stock')->default(0);    // 库存
            $table->string('image')->nullable();             // 变体默认图
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('product_id');
            $table->index('sku');
        });
    }

    /**
     * 回滚变体表。
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
