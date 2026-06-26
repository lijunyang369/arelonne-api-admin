<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * 商品详情 JSON 转换（含变体和图片）。
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'category'    => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'base_price'  => $this->base_price,
            'sale_price'  => $this->sale_price,
            'status'      => $this->status,
            'meta'        => $this->meta,
            'variants'    => VariantResource::collection($this->whenLoaded('variants')),
            'images'      => ProductImageResource::collection($this->whenLoaded('images')),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
