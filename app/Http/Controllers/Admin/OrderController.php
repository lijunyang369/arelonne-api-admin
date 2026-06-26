<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * 订单列表（后台，支持按状态筛选）。
     */
    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 200);
    }

    /**
     * 订单详情。
     */
    public function show(int $id): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 200);
    }

    /**
     * 更新订单状态（如：待处理 → 已发货）。
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 200);
    }
}
