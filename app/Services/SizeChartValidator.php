<?php

namespace App\Services;

/**
 * 尺码表 JSON 校验器。
 *
 * 负责校验 Codex 输出的 size-data.json 文件结构，
 * 确保数据合法后再交给 Command 写入数据库。
 */
class SizeChartValidator
{
    /** @var array<int, string> 允许的测量维度 */
    private const ALLOWED_MEASURE_KEYS = [
        'bust', 'waist', 'hip', 'length', 'sleeve', 'shoulder', 'inseam', 'us',
    ];

    /** @var array<int, string> 允许的 unit 值 */
    private const ALLOWED_UNITS = ['cm', 'in'];

    /**
     * 校验测量值是否合法：正数或范围字符串（如 "76-82"）。
     *
     * @param  string  $key  测量维度 key（如 "bust"、"us"），用于区分 US 尺码标签允许零值
     */
    private function isValidMeasurement(mixed $value, string $key): bool
    {
        // 非 US 字段只允许正数；US 尺码标签（如 "00"）允许 >= 0
        if (is_numeric($value)) {
            $min = $key === 'us' ? 0 : 0.01;
            return (float) $value >= $min;
        }

        if (is_string($value) && preg_match('/^(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)$/', $value, $m)) {
            // 校验范围下限 <= 上限
            return (float) $m[1] <= (float) $m[2];
        }

        return false;
    }

    /**
     * 校验 JSON 数据。
     *
     * @param  array  $json  已 json_decode 的关联数组
     * @return array  ['colors' => string[], 'sizes' => string[], 'size_chart' => array]
     *
     * @throws \InvalidArgumentException  校验失败
     */
    public function validate(array $json): array
    {
        // 1. sizes 必须存在且为非空数组
        if (empty($json['sizes']) || ! is_array($json['sizes'])) {
            throw new \InvalidArgumentException('缺少 "sizes" 字段或为空。');
        }

        // 2. size_chart 必须存在且为非空关联数组
        if (empty($json['size_chart']) || ! is_array($json['size_chart'])) {
            throw new \InvalidArgumentException('缺少 "size_chart" 字段或为空。');
        }

        // 3. colors 可选，提供时必须为非空字符串数组
        $colors = $json['colors'] ?? [];
        if (! is_array($colors)) {
            throw new \InvalidArgumentException('"colors" 字段必须为数组。');
        }
        foreach ($colors as $i => $color) {
            if (! is_string($color) || trim($color) === '') {
                throw new \InvalidArgumentException("\"colors\" 第 " . ($i + 1) . " 个元素必须为非空字符串。");
            }
        }

        $sizeChart = $json['size_chart'];

        // 4. unit 校验
        if (empty($sizeChart['unit']) || ! in_array($sizeChart['unit'], self::ALLOWED_UNITS, true)) {
            throw new \InvalidArgumentException(
                'size_chart.unit 必须为 "' . implode('" 或 "', self::ALLOWED_UNITS) . '"'
            );
        }

        // 5. 每个尺码标签下的测量 key 白名单校验
        foreach ($sizeChart as $sizeLabel => $measurements) {
            if ($sizeLabel === 'unit') {
                continue;
            }

            if (! is_array($measurements)) {
                throw new \InvalidArgumentException(
                    "size_chart.{$sizeLabel} 必须是一个对象。"
                );
            }

            foreach ($measurements as $key => $value) {
                if (! in_array($key, self::ALLOWED_MEASURE_KEYS, true)) {
                    throw new \InvalidArgumentException(
                        "size_chart.{$sizeLabel} 包含未知测量 key \"{$key}\"，" .
                        '允许: ' . implode(', ', self::ALLOWED_MEASURE_KEYS)
                    );
                }

                if (! $this->isValidMeasurement($value, $key)) {
                    throw new \InvalidArgumentException(
                        "size_chart.{$sizeLabel}.{$key} 必须为正数或范围字符串（如 76-82）。"
                    );
                }
            }
        }

        return [
            'colors'     => $colors,
            'sizes'      => $json['sizes'],
            'size_chart' => $sizeChart,
        ];
    }
}
