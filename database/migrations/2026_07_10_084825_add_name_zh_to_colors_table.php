<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为颜色表添加中文名称列。
     */
    public function up(): void
    {
        Schema::table('colors', function (Blueprint $table) {
            $table->string('name_zh')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('colors', function (Blueprint $table) {
            $table->dropColumn('name_zh');
        });
    }
};
