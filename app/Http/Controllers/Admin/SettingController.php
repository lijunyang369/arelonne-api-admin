<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * 获取站点设置（可按 group 过滤）。
     *
     * 运费设置缺失时返回有效默认值（与 Store 端回退一致）。
     */
    public function index(Request $request): JsonResponse
    {
        $group = $request->query('group');

        $query = Setting::query();
        if ($group) {
            $query->where('group', $group);
        }

        $settings = $query->get()->map(fn (Setting $s) => [
            'key'   => $s->key,
            'value' => $s->value,
            'type'  => $s->type,
            'group' => $s->group,
        ])->values();

        // 运费设置的默认值（记录缺失时补充，与 Store 端回退一致）
        $shippingDefaults = [
            ['key' => 'shipping.free_threshold', 'value' => '69', 'type' => 'number', 'group' => 'shipping'],
            ['key' => 'shipping.fee', 'value' => '8.99', 'type' => 'number', 'group' => 'shipping'],
        ];

        if (! $group || $group === 'shipping') {
            foreach ($shippingDefaults as $default) {
                if (! $settings->contains('key', $default['key'])) {
                    $settings->push($default);
                }
            }
        }

        return response()->json(['data' => $settings]);
    }

    /**
     * 更新站点设置（upsert）并推送到 Store。
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings'           => 'required|array|min:1',
            'settings.*.key'     => 'required|string|max:255',
            'settings.*.value'   => 'required',
        ]);

        // 先整体校验（scalar 守卫 + 运费非负），全部通过后才写入，避免部分写入
        foreach ($data['settings'] as $item) {
            $key   = $item['key'];
            $value = $item['value'];

            // 值仅支持标量（字符串/数字/布尔），数组对象直接拒绝
            if (! is_scalar($value)) {
                return response()->json([
                    'message' => 'Setting values must be scalar.',
                    'errors'  => [$key => ['Setting values must be scalar.']],
                ], 422);
            }

            // 运费设置必须为非负数（key 前缀 shipping. 的数值配置）
            if (str_starts_with($key, 'shipping.') &&
                (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 100000)) {
                return response()->json([
                    'message' => 'Shipping settings must be non-negative numbers.',
                    'errors'  => [$key => ['Shipping settings must be non-negative numbers.']],
                ], 422);
            }
        }

        // 收集规范化后的行（与 DB 写入一致），用于推送 Store
        $synced = [];

        foreach ($data['settings'] as $item) {
            $key   = $item['key'];
            $value = $item['value'];
            // group 取 key 前缀（如 "shipping.fee" → "shipping"）
            $group = str_contains($key, '.') ? explode('.', $key)[0] : 'general';
            $type  = is_bool($value) ? 'boolean' : (is_numeric($value) ? 'number' : 'string');

            // 规范化存储值：布尔 → '1'/'0'，其余 → 字符串
            $storedValue = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            $synced[] = ['key' => $key, 'value' => $storedValue, 'type' => $type];

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $storedValue, 'type' => $type, 'group' => $group]
            );
        }

        // 推送到 🇺🇸 Store（失败不阻塞保存）— 推送与 DB 一致的规范化值
        SyncService::push('/settings', ['settings' => $synced], 'POST', 'store');

        return response()->json(['data' => ['message' => 'Settings updated']]);
    }
}
