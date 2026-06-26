<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建商品主表。
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');                          // 商品名（英文）
            $table->string('slug')->unique();                // URL 标识
            $table->text('description')->nullable();         // 商品描述
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_price', 10, 2);            // 基础售价（USD）
            $table->decimal('sale_price', 10, 2)->nullable();// 折扣价
            $table->decimal('cost_price', 10, 2)->nullable();// 成本价（内部）
            $table->string('status')->default('draft');      // draft / active / inactive
            $table->unsignedInteger('sort')->default(0);
            $table->json('meta')->nullable();                // 扩展字段（面料、洗护等）
            $table->timestamps();
            $table->softDeletes();                           // 软删除

            $table->index('slug');
            $table->index('category_id');
            $table->index('status');
        });
    }

    /**
     * 回滚商品表。
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
