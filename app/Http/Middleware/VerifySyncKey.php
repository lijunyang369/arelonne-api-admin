<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifySyncKey
{
    /**
     * 验证对端同步请求携带的 X-Sync-Key 头。
     *
     * MVP 阶段用 APP_KEY 做简单验证；生产环境换独立密钥。
     */
    public function handle(Request $request, Closure $next): JsonResponse
    {
        $key = $request->header('X-Sync-Key');

        if (! $key || $key !== config('app.key')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
