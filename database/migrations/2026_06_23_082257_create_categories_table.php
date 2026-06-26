<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建商品分类表。
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');                // 分类名（中/英）
            $table->string('slug')->unique();      // URL 友好标识
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);  // 排序
            $table->string('status')->default('active');  // active / inactive
            $table->timestamps();

            $table->index('slug');
            $table->index('parent_id');
        });
    }

    /**
     * 回滚分类表。
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
