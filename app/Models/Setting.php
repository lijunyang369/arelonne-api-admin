<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key', 'value', 'type', 'group',
    ];

    /**
     * 按 key 获取配置值，自动根据 type 转换。
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'json'    => json_decode($setting->value, true),
            'boolean' => (bool) $setting->value,
            'number'  => is_numeric($setting->value) ? $setting->value + 0 : $default,
            default   => $setting->value,
        };
    }

    /**
     * 按 key 设置配置值。
     */
    public static function setValue(string $key, mixed $value, string $type = 'string'): void
    {
        $val = match ($type) {
            'json'    => json_encode($value, JSON_UNESCAPED_UNICODE),
            'boolean' => $value ? '1' : '0',
            default   => (string) $value,
        };

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $val, 'type' => $type]
        );
    }
}
