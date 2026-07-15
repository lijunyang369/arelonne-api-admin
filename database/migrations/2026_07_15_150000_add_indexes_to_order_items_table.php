<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为 order_items 表的外键字段补充索引。
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('product_id');
            $table->index('product_variant_id');
        });
    }

    /**
     * 回滚索引。
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['product_id']);
            $table->dropIndex(['product_variant_id']);
        });
    }
};
