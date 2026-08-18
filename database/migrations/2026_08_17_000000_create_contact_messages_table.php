<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建联系我们留言表（🇨🇳 Admin 侧镜像，接收 🇺🇸 Store 同步）。
     */
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);                     // 客户姓名
            $table->string('email', 200);                    // 客户邮箱
            $table->string('phone', 30)->nullable();         // 电话（可选）
            $table->string('order_number', 50)->nullable();  // 订单号（可选，帮助快速定位）
            $table->string('reason', 40);                    // 事由：order_status / cancel_change / damaged_missing / returns_replacements / size_fit / other
            $table->text('message');                         // 留言内容
            $table->string('status', 20)->default('new');    // new / replied / closed
            $table->timestamps();

            $table->index('email');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * 回滚留言表。
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
