<?php

namespace Tests\Unit\Services;

use App\Services\SizeChartValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SizeChartValidatorTest extends TestCase
{
    private SizeChartValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SizeChartValidator();
    }

    #[Test]
    public function 校验有效数据()
    {
        $data = [
            'sizes' => ['S', 'M', 'L'],
            'size_chart' => [
                'unit' => 'cm',
                'S' => ['bust' => 80, 'waist' => 60, 'hip' => 88],
                'M' => ['bust' => 84, 'waist' => 64, 'hip' => 92],
                'L' => ['bust' => 88, 'waist' => 68, 'hip' => 96],
            ],
        ];

        $result = $this->validator->validate($data);

        $this->assertSame(['S', 'M', 'L'], $result['sizes']);
        $this->assertSame('cm', $result['size_chart']['unit']);
    }

    #[Test]
    public function 缺少sizes抛出异常()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sizes');

        $this->validator->validate([
            'size_chart' => ['unit' => 'cm'],
        ]);
    }

    #[Test]
    public function 空sizes抛出异常()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sizes');

        $this->validator->validate([
            'sizes' => [],
            'size_chart' => ['unit' => 'cm'],
        ]);
    }

    #[Test]
    public function 缺少size_chart抛出异常()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('size_chart');

        $this->validator->validate(['sizes' => ['S', 'M', 'L']]);
    }

    #[Test]
    public function unit非法抛出异常()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unit');

        $this->validator->validate([
            'sizes' => ['S', 'M', 'L'],
            'size_chart' => [
                'unit' => 'mm',
                'S' => ['bust' => 80],
            ],
        ]);
    }

    #[Test]
    public function 未知测量key抛出异常()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未知测量');

        $this->validator->validate([
            'sizes' => ['S'],
            'size_chart' => [
                'unit' => 'cm',
                'S' => ['color' => 'red'],
            ],
        ]);
    }

    #[Test]
    public function 测量值非正数抛出异常()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('正数');

        $this->validator->validate([
            'sizes' => ['S'],
            'size_chart' => [
                'unit' => 'cm',
                'S' => ['bust' => -5],
            ],
        ]);
    }

    #[Test]
    public function 测量值为零抛出异常()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('正数');

        $this->validator->validate([
            'sizes' => ['S'],
            'size_chart' => [
                'unit' => 'cm',
                'S' => ['bust' => 0],
            ],
        ]);
    }

    #[Test]
    public function 测量值为字符串数字视为有效()
    {
        $data = [
            'sizes' => ['S'],
            'size_chart' => [
                'unit' => 'in',
                'S' => ['bust' => '36', 'waist' => '28'],
            ],
        ];

        $result = $this->validator->validate($data);

        $this->assertSame('in', $result['size_chart']['unit']);
        $this->assertSame(['S'], $result['sizes']);
    }

    #[Test]
    public function 测量值不是对象则抛出异常()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('必须是一个对象');

        $this->validator->validate([
            'sizes' => ['S'],
            'size_chart' => [
                'unit' => 'cm',
                'S' => 'not-an-object',
            ],
        ]);
    }
}
