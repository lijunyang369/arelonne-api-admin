<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 上传记录。
 */
class Upload extends Model
{
    protected $fillable = [
        'key', 'type', 'mime', 'size', 'status', 'processing_at',
        'final_path', 'thumb_path', 'width', 'height', 'error',
    ];

    protected function casts(): array
    {
        return [
            'size'          => 'integer',
            'width'         => 'integer',
            'height'        => 'integer',
            'processing_at' => 'datetime',
        ];
    }
}
