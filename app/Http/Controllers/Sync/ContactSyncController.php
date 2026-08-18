<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContactSyncController extends Controller
{
    /**
     * 接收 🇺🇸 Store 推送的新留言，写入 🇨🇳 Admin 侧。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'           => 'required|integer',
            'name'         => 'required|string|max:100',
            'email'        => 'required|email|max:200',
            'phone'        => 'nullable|string|max:30',
            'order_number' => 'nullable|string|max:50',
            'reason'       => 'required|string|max:40',
            'message'      => 'required|string|max:5000', // 与 Store 入口一致，超长载荷返回 422 而非写库报错
            'status'       => 'required|string|max:20',
        ]);

        // Upsert 留言
        $contact = ContactMessage::updateOrCreate(
            ['id' => $data['id']],
            [
                'name'         => $data['name'],
                'email'        => $data['email'],
                'phone'        => $data['phone'] ?? null,
                'order_number' => $data['order_number'] ?? null,
                'reason'       => $data['reason'],
                'message'      => $data['message'],
                'status'       => $data['status'],
            ]
        );

        Log::info("[Sync] 留言同步完成: #{$contact->id}");

        return response()->json(['id' => $contact->id], 200);
    }
}
