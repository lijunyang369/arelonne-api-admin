<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 颜色资源 — 将 Color 模型转换为前端可用的 { name, hex } 对象。
 */
class ColorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'name_zh'    => $this->name_zh,
            'hex'        => $this->hex,
            'status'     => $this->status,
            'updated_at' => $this->updated_at?->toISOString(),
            'updated_by' => $this->updated_by,
        ];
    }
}
