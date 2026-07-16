<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * products 表增加 primary_skc_id — 指向默认/主色 SKC。
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('primary_skc_id')->nullable()->after('category_id')
                  ->constrained('product_skcs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['primary_skc_id']);
            $table->dropColumn('primary_skc_id');
        });
    }
};
