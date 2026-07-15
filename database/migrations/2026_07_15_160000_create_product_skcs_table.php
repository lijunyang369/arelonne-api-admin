<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建商品 SKC（Stock Keeping Color）表。
     * 每个商品按颜色维度拆分，一个 SKC 关联多张图片。
     */
    public function up(): void
    {
        Schema::create('product_skcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('color');                                // 颜色名（英文）
            $table->string('color_hex', 7)->nullable();             // 颜色 hex 值
            $table->string('slug')->unique();                       // URL 标识
            $table->string('status')->default('active');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_id', 'color']);
            $table->index('status');
        });
    }

    /**
     * 回滚 SKC 表。
     */
    public function down(): void
    {
        Schema::dropIfExists('product_skcs');
    }
};
