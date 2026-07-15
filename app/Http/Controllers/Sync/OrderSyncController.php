<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderSyncController extends Controller
{
    /**
     * 接收 🇺🇸 Store 推送的新订单，写入 🇨🇳 Admin 侧。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'              => 'required|integer',
            'order_no'        => 'required|string',
            'customer_email'  => 'required|email',
            'customer_name'   => 'required|string',
            'customer_phone'  => 'nullable|string',
            'subtotal'        => 'required|numeric',
            'shipping_fee'    => 'numeric',
            'tax'             => 'numeric',
            'discount'        => 'numeric',
            'total'           => 'required|numeric',
            'currency'        => 'string',
            'shipping_address'=> 'required|array',
            'billing_address' => 'nullable|array',
            'status'          => 'required|string',
            'payment_status'  => 'required|string',
            'payment_method'  => 'nullable|string',
            'payment_id'      => 'nullable|string',
            'items'           => 'required|array',
        ]);

        // Upsert 订单
        $order = Order::updateOrCreate(
            ['id' => $data['id']],
            [
                'order_no'         => $data['order_no'],
                'customer_email'   => $data['customer_email'],
                'customer_name'    => $data['customer_name'],
                'customer_phone'   => $data['customer_phone'] ?? null,
                'subtotal'         => $data['subtotal'],
                'shipping_fee'     => $data['shipping_fee'] ?? 0,
                'tax'              => $data['tax'] ?? 0,
                'discount'         => $data['discount'] ?? 0,
                'total'            => $data['total'],
                'currency'         => $data['currency'] ?? 'USD',
                'shipping_address' => $data['shipping_address'],
                'billing_address'  => $data['billing_address'] ?? null,
                'status'           => $data['status'],
                'payment_status'   => $data['payment_status'],
                'payment_method'   => $data['payment_method'] ?? null,
                'payment_id'       => $data['payment_id'] ?? null,
            ]
        );

        // 订单明细
        $order->items()->delete();
        foreach ($data['items'] as $item) {
            $order->items()->create([
                'product_id'         => $item['product_id'] ?? null,
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'product_name'       => $item['product_name'],
                'sku'                => $item['sku'] ?? '',
                'color'              => $item['color'] ?? '',
                'size'               => $item['size'] ?? '',
                'price'              => $item['price'],
                'quantity'           => $item['quantity'],
                'subtotal'           => $item['subtotal'],
            ]);
        }

        Log::info("[Sync] 订单同步完成: {$order->order_no}");

        return response()->json(['id' => $order->id], 200);
    }

    /**
     * 接收 🇺🇸 Store 推送的订单状态变更。
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status'         => 'string',
            'payment_status' => 'string',
            'tracking_number'=> 'nullable|string',
        ]);

        $order = Order::find($id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $order->fill(array_filter($data))->save();
        Log::info("[Sync] 订单 #{$id} 状态同步: " . json_encode($data));

        return response()->json(['id' => $order->id]);
    }
}
