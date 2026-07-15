<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Color extends Model
{
    protected $fillable = [
        'name', 'name_zh', 'hex', 'status', 'updated_by',
    ];

    protected function casts(): array
    {
        return [];
    }

    /**
     * 仅活跃的颜色。
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
