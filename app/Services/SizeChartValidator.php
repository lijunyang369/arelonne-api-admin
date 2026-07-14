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
        'bust', 'waist', 'hip', 'length', 'sleeve', 'shoulder', 'inseam',
    ];

    /** @var array<int, string> 允许的 unit 值 */
    private const ALLOWED_UNITS = ['cm', 'in'];

    /**
     * 校验 JSON 数据。
     *
     * @param  array  $json  已 json_decode 的关联数组
     * @return array  ['sizes' => string[], 'size_chart' => array]
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

        $sizeChart = $json['size_chart'];

        // 3. unit 校验
        if (empty($sizeChart['unit']) || ! in_array($sizeChart['unit'], self::ALLOWED_UNITS, true)) {
            throw new \InvalidArgumentException(
                'size_chart.unit 必须为 "' . implode('" 或 "', self::ALLOWED_UNITS) . '"'
            );
        }

        // 4. 每个尺码标签下的测量 key 白名单校验
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

                if (! is_numeric($value) || $value <= 0) {
                    throw new \InvalidArgumentException(
                        "size_chart.{$sizeLabel}.{$key} 必须为正数。"
                    );
                }
            }
        }

        return [
            'sizes'      => $json['sizes'],
            'size_chart' => $sizeChart,
        ];
    }
}
