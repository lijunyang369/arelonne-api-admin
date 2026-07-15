<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 将 product_images 的 product_variant_id 替换为 product_skc_id。
     * 图片不再挂在 variant 级别，改为挂在 SKC 级别。
     */
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            // 删除旧外键和字段
            $table->dropForeign(['product_variant_id']);
            $table->dropColumn('product_variant_id');

            // 新增 SKC 外键
            $table->foreignId('product_skc_id')->nullable()->after('product_id')->constrained()->cascadeOnDelete();
            $table->index('product_skc_id');
        });
    }

    /**
     * 回滚：恢复 product_variant_id，删除 product_skc_id。
     * 注意：此操作不可完全恢复数据，仅恢复表结构。
     */
    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropForeign(['product_skc_id']);
            $table->dropColumn('product_skc_id');

            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->index('product_variant_id');
        });
    }
};
