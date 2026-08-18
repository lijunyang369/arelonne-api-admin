<?php

namespace App\Http\Resources;

use App\Support\ImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VariantResource extends JsonResource
{
    /**
     * SKU 变体 JSON 转换（图片 URL 拼 CDN 基址）。
     */
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'sku'   => $this->sku,
            'color' => $this->color,
            'size'  => $this->size,
            'price' => $this->price ?? $this->product?->base_price,
            'stock' => $this->stock,
            'image' => ImageUrl::absolute($this->image),
        ];
    }
}
