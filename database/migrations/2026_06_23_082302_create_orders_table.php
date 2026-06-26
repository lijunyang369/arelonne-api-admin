<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建订单主表（支持 Guest Checkout）。
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique();            // 订单号（如 HOLP-20260801-0001）
            $table->string('customer_email');                // 客户邮箱
            $table->string('customer_name');                 // 客户姓名
            $table->string('customer_phone')->nullable();    // 电话（可选）
            $table->decimal('subtotal', 10, 2);              // 商品小计
            $table->decimal('shipping_fee', 10, 2)->default(0); // 运费
            $table->decimal('tax', 10, 2)->default(0);       // 税费
            $table->decimal('discount', 10, 2)->default(0);   // 折扣
            $table->decimal('total', 10, 2);                 // 实付总额
            $table->string('currency')->default('USD');
            $table->json('shipping_address');                // 配送地址 JSON
            $table->json('billing_address')->nullable();     // 账单地址
            $table->string('status')->default('pending');    // pending / confirmed / shipped / delivered / cancelled / refunded
            $table->string('payment_status')->default('unpaid'); // unpaid / paid / refunded / partial_refund
            $table->string('payment_method')->nullable();    // stripe / paypal / ...
            $table->string('payment_id')->nullable();        // 第三方支付 ID
            $table->string('tracking_number')->nullable();   // 物流单号
            $table->text('notes')->nullable();               // 备注
            $table->timestamps();
            $table->softDeletes();

            $table->index('order_no');
            $table->index('customer_email');
            $table->index('status');
            $table->index('payment_status');
            $table->index('created_at');
        });
    }

    /**
     * 回滚订单表。
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
