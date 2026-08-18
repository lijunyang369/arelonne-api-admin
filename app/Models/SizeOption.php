<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SizeOption extends Model
{
    protected $fillable = [
        'name', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }
}
