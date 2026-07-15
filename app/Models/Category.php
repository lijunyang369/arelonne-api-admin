<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Category extends Model
{
    protected $fillable = [
        'name', 'slug', 'parent_id', 'sort', 'status',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    /**
     * 子分类。
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * 父分类。
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * 该分类下的商品。
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
