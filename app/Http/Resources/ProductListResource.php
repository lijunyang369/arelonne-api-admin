<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductListResource extends JsonResource
{
    /**
     * 商品列表 JSON 转换（精简版，不含变体和图片详情）。
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'slug'       => $this->slug,
            'category'   => $this->whenLoaded('category', fn () => $this->category->name),
            'base_price' => $this->base_price,
            'sale_price' => $this->sale_price,
            'status'     => $this->status,
            'image'      => $this->whenLoaded('images',
                fn () => $this->images->where('is_primary', true)->first()?->url
                    ?? $this->images->first()?->url
            ),
        ];
    }
}
