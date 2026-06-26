<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VariantResource extends JsonResource
{
    /**
     * SKU 变体 JSON 转换。
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
            'image' => $this->image,
        ];
    }
}
