<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    /**
     * 更新规则:slug 禁止提交(创建后锁定);其余可改。
     */
    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'string', 'max:255'],
            'slug'      => ['prohibited'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'sort'      => ['sometimes', 'integer', 'min:0'],
            'status'    => ['sometimes', 'in:active,inactive'],
        ];
    }
}
