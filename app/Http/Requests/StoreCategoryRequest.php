<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    /** 保留 slug — 与前台 collection 路由冲突,禁止用作分类 slug */
    public const RESERVED_SLUGS = ['all', 'bras', 'linen', 'cotton-linen', 'bras-innerwear'];

    /**
     * 校验规则:slug 允许为空(Service 按 name 生成)。
     */
    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:255'],
            'slug'      => ['nullable', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(self::RESERVED_SLUGS),
                'unique:categories,slug'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sort'      => ['integer', 'min:0'],
            'status'    => ['required', 'in:active,inactive'],
        ];
    }
}
