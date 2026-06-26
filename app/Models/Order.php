<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_no', 'customer_email', 'customer_name', 'customer_phone',
        'subtotal', 'shipping_fee', 'tax', 'discount', 'total', 'currency',
        'shipping_address', 'billing_address',
        'status', 'payment_status', 'payment_method', 'payment_id',
        'tracking_number', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal'          => 'decimal:2',
            'shipping_fee'      => 'decimal:2',
            'tax'               => 'decimal:2',
            'discount'          => 'decimal:2',
            'total'             => 'decimal:2',
            'shipping_address'  => 'array',
            'billing_address'   => 'array',
        ];
    }

    /**
     * 订单明细。
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
