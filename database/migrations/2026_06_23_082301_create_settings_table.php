<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建站点配置表（key-value 存储）。
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();                // 配置键（如 site_logo, announcement）
            $table->text('value')->nullable();               // 配置值
            $table->string('type')->default('string');       // string / json / boolean / number
            $table->string('group')->default('general');     // 分组（general / shipping / payment / email）
            $table->timestamps();

            $table->index('key');
            $table->index('group');
        });
    }

    /**
     * 回滚配置表。
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
