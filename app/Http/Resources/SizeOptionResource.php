<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 尺码选项资源 — 将 SizeOption 模型转换为前端可用的 { id, name, sort } 对象。
 */
class SizeOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'name' => $this->name,
            'sort' => $this->sort,
        ];
    }
}
