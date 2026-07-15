<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 将 product_variants 的 color 和 size 改为非空，
     * 解决 MySQL UNIQUE 索引对 NULL 不生效的问题。
     *
     * 不可逆原因：回滚无法区分原始 NULL 和迁移后写入的空字符串。
     */
    public function up(): void
    {
        // 1. 将已有 NULL 值替换为空字符串
        DB::table('product_variants')->whereNull('color')->update(['color' => '']);
        DB::table('product_variants')->whereNull('size')->update(['size' => '']);

        // 2. 修改字段为非空，默认空字符串
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('color')->default('')->change();
            $table->string('size')->default('')->change();
        });
    }

    /**
     * 回滚：恢复为可空字段。
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('color')->nullable()->default(null)->change();
            $table->string('size')->nullable()->default(null)->change();
        });
    }
};
