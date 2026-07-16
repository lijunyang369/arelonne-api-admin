<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'category_id',
        'base_price', 'sale_price', 'cost_price',
        'primary_skc_id', 'status', 'sort', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'base_price'  => 'decimal:2',
            'sale_price'  => 'decimal:2',
            'cost_price'  => 'decimal:2',
            'meta'        => 'array',
            'sort'        => 'integer',
        ];
    }

    /**
     * 所属分类。
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * 商品变体（SKU）。
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * 主色 SKC（商品封面/默认颜色）。
     */
    public function primarySkc(): BelongsTo
    {
        return $this->belongsTo(ProductSkc::class, 'primary_skc_id');
    }

    /**
     * 商品 SKC（按颜色）。
     */
    public function skcs(): HasMany
    {
        return $this->hasMany(ProductSkc::class);
    }

    /**
     * 商品图片。
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }
}
