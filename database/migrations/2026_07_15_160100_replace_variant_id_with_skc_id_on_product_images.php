<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 将 product_images 的 product_variant_id 替换为 product_skc_id。
     * 图片不再挂在 variant 级别，改为挂在 SKC 级别。
     */
    public function up(): void
    {
        // SQLite 不支持直接删除被索引/外键引用的列（会报 unknown column in foreign key definition），
        // 只能重建表；MySQL 可直接删除，保持原逻辑不变。
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildForSqlite();

            return;
        }

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
     * SQLite 重建 product_images 表：去掉 product_variant_id、新增 product_skc_id，并保留数据。
     */
    private function rebuildForSqlite(): void
    {
        // 1. 建临时表（目标结构）
        Schema::create('product_images_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_skc_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('alt')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('product_id');
            $table->index('product_skc_id');
        });

        // 2. 拷贝数据（product_skc_id 尚不可映射，置空由后续逻辑填充）
        DB::table('product_images_new')->insertUsing(
            ['id', 'product_id', 'url', 'alt', 'sort', 'is_primary', 'created_at', 'updated_at'],
            DB::table('product_images')->select('id', 'product_id', 'url', 'alt', 'sort', 'is_primary', 'created_at', 'updated_at')
        );

        // 3. 删除旧表并重命名（SQLite 表重建的索引名仍带 _new 后缀，仅影响测试库，无后续迁移引用）
        Schema::drop('product_images');
        Schema::rename('product_images_new', 'product_images');
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
