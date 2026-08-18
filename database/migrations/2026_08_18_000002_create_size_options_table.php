<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建尺码选项表。
     * 尺码通过名称与 product_variants.size 关联,前端通过 API 获取排序。
     */
    public function up(): void
    {
        Schema::create('size_options', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();                // 尺码名,如 "XS"
            $table->unsignedInteger('sort')->default(0);     // 排序(升序,最小 0)
            $table->timestamps();

            $table->index('name');
        });

        // 初始尺码数据:XS/S/M/L/XL(sort 1-5)
        $sizes = ['XS' => 1, 'S' => 2, 'M' => 3, 'L' => 4, 'XL' => 5];
        foreach ($sizes as $name => $sort) {
            DB::table('size_options')->insert([
                'name'       => $name,
                'sort'       => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * 回滚尺码选项表。
     */
    public function down(): void
    {
        Schema::dropIfExists('size_options');
    }
};
