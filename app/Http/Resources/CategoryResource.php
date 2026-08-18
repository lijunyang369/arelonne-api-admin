<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * 分类响应契约:管理页完整字段(含 parent_id/status/sort)。
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'slug'      => $this->slug,
            'parent_id' => $this->parent_id,
            'status'    => $this->status,
            'sort'      => $this->sort,
            'children'  => $this->whenLoaded('children', fn () => CategoryResource::collection($this->children)),
        ];
    }
}
