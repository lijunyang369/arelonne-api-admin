<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SKC JSON 转换（含颜色与图片）。
 */
class ProductSkcResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'color'     => $this->color,
            'color_hex' => $this->color_hex,
            'slug'      => $this->slug,
            'status'    => $this->status,
            'sort'      => $this->sort,
            'images'    => ProductImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
