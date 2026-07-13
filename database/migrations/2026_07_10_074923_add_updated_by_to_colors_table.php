<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为颜色表添加操作人追踪字段。
     */
    public function up(): void
    {
        Schema::table('colors', function (Blueprint $table) {
            $table->string('updated_by')->nullable()->after('status');
        });
    }

    /**
     * 回滚。
     */
    public function down(): void
    {
        Schema::table('colors', function (Blueprint $table) {
            $table->dropColumn('updated_by');
        });
    }
};
