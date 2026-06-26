<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 跨站点数据同步服务。
 *
 * Admin（🇨🇳）→ Store（🇺🇸）：商品 / 分类 / 配置
 * Store（🇺🇸）→ Admin（🇨🇳）：订单
 */
class SyncService
{
    /**
     * 推送数据到对端 Sync 接口。
     *
     * @param  string  $endpoint  如 "/products/42"
     * @param  array   $payload   数据体
     * @param  string  $method    HTTP 方法
     * @param  string  $target    目标角色（store / admin）
     * @return bool    是否成功
     */
    public static function push(
        string $endpoint,
        array $payload = [],
        string $method = 'POST',
        string $target = 'store',
    ): bool {
        $baseUrl = config("role.sync.{$target}");

        if (! $baseUrl) {
            Log::warning("[Sync] 未配置 {$target} 同步地址，跳过推送。");
            return true; // 不阻塞主流程
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->withHeaders(['X-Sync-Key' => config('app.key')])
                ->send($method, "{$baseUrl}/api/sync{$endpoint}", [
                    'json' => $payload,
                ]);

            if ($response->successful()) {
                Log::info("[Sync] ✓ {$method} {$endpoint} → {$target}");
                return true;
            }

            Log::error("[Sync] ✗ {$method} {$endpoint} → {$target}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error("[Sync] ✗ {$method} {$endpoint} → {$target}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 异步推送（放入队列，不阻塞当前请求）。
     */
    public static function pushAsync(
        string $endpoint,
        array $payload = [],
        string $method = 'POST',
        string $target = 'store',
    ): void {
        // MVP 阶段同步调用；V1 后改为 dispatch Job
        static::push($endpoint, $payload, $method, $target);
    }
}
