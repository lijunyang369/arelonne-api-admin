<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上传记录表：confirm 幂等（重试返回相同结果）与并发安全（CAS 状态机 + 崩溃恢复）的依据。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('S3 待确认 key');
            $table->string('type')->comment('banner/editorial/product-shot');
            $table->string('mime')->comment('声明 MIME');
            $table->unsignedBigInteger('size')->comment('声明字节数');
            $table->string('status')->default('pending')->index()->comment('pending/processing/confirmed/failed');
            $table->timestamp('processing_at')->nullable()->comment('进入 processing 时间（崩溃残留超时恢复）');
            $table->string('final_path')->nullable()->comment('正式相对路径');
            $table->string('thumb_path')->nullable()->comment('480 缩略图相对路径');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->text('error')->nullable()->comment('失败原因');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
