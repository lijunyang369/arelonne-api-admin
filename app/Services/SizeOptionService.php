<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\SizeOption;
use Illuminate\Validation\ValidationException;

class SizeOptionService
{
    /**
     * 删除尺码:被商品变体引用的尺码拒绝删除。
     */
    public function destroy(SizeOption $option): void
    {
        // 商品变体的 size 字段按名称引用尺码,存在引用则禁止删除
        if (ProductVariant::where('size', $option->name)->exists()) {
            throw ValidationException::withMessages([
                'name' => '该尺码已被商品使用,请先处理对应商品',
            ]);
        }

        $option->delete();
    }
}
