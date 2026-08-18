<?php

namespace App\Http\Resources;

use App\Support\ImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductImageResource extends JsonResource
{
    /**
     * 商品图片 JSON 转换（URL 拼 CDN 基址）。
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'url'        => ImageUrl::absolute($this->url),
            'thumb_url'  => ImageUrl::thumb($this->url),
            'alt'        => $this->alt,
            'sort'       => $this->sort,
            'is_primary' => $this->is_primary,
        ];
    }
}
