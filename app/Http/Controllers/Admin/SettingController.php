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
            ['key' => 'shipping.free_threshold', 'value' => '50', 'type' => 'number', 'group' => 'shipping'],
            ['key' => 'shipping.fee', 'value' => '5.99', 'type' => 'number', 'group' => 'shipping'],
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

        // 运费设置必须为非负数（key 前缀 shipping. 的数值配置）
        foreach ($data['settings'] as $item) {
            if (str_starts_with($item['key'], 'shipping.') &&
                (! is_numeric($item['value']) || ! is_finite((float) $item['value']) || (float) $item['value'] < 0 || (float) $item['value'] > 100000)) {
                return response()->json([
                    'message' => 'Shipping settings must be non-negative numbers.',
                    'errors'  => [$item['key'] => ['Shipping settings must be non-negative numbers.']],
                ], 422);
            }
        }

        foreach ($data['settings'] as $item) {
            $key   = $item['key'];
            $value = $item['value'];
            // group 取 key 前缀（如 "shipping.fee" → "shipping"）
            $group = str_contains($key, '.') ? explode('.', $key)[0] : 'general';
            $type  = is_numeric($value) ? 'number' : 'string';

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value, 'type' => $type, 'group' => $group]
            );
        }

        // 推送到 🇺🇸 Store（失败不阻塞保存）
        SyncService::push('/settings', ['settings' => $data['settings']], 'POST', 'store');

        return response()->json(['data' => ['message' => 'Settings updated']]);
    }
}
