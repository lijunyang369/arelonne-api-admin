<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * 管理员登录，验证邮箱和密码，返回 Sanctum API Token。
     *
     * @param  Request  $request  需包含 email 和 password 字段
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        // 验证输入
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // 尝试验证
        if (! auth()->attempt($credentials)) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        /** @var \App\Models\User $user */
        $user = auth()->user();

        // 创建 Sanctum token（过期时间 24 小时）
        $token = $user->createToken(
            'admin-token',
            ['*'],
            now()->addHours(24)
        )->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'data'    => [
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                ],
                'token'      => $token,
                'token_type' => 'Bearer',
                'expires_at' => now()->addHours(24)->toIso8601String(),
            ],
        ]);
    }
}
