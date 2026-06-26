<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建订单明细表。
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');                  // 下单时快照的商品名
            $table->string('sku');                           // 下单时快照的 SKU
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->decimal('price', 10, 2);                 // 下单时单价
            $table->unsignedInteger('quantity');
            $table->decimal('subtotal', 10, 2);              // price × quantity
            $table->timestamps();

            $table->index('order_id');
        });
    }

    /**
     * 回滚明细表。
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
