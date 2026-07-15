<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductSkc extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id', 'color', 'color_hex',
        'slug', 'status', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    /**
     * 所属商品。
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * SKC 下的图片。
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_skc_id')->orderBy('sort')->orderBy('id');
    }
}
