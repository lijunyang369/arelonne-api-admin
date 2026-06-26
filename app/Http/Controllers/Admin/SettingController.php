<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * 获取站点设置。
     */
    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 200);
    }

    /**
     * 更新站点设置。
     */
    public function update(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 200);
    }
}
