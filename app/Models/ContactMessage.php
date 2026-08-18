<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    /**
     * id 必须可批量赋值 — 同步时保留 🇺🇸 Store 源 ID，
     * 否则漏同步后的重试会因自增 ID 漂移而更新错行。
     */
    protected $fillable = [
        'id', 'name', 'email', 'phone', 'order_number', 'reason', 'message', 'status',
    ];
}
