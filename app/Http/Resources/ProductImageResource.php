<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductImageResource extends JsonResource
{
    /**
     * 商品图片 JSON 转换。
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'url'        => $this->url,
            'alt'        => $this->alt,
            'sort'       => $this->sort,
            'is_primary' => $this->is_primary,
        ];
    }
}
