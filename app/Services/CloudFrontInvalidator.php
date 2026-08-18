<?php

namespace App\Services;

use Aws\CloudFront\CloudFrontClient;
use Illuminate\Support\Facades\Log;

/**
 * CloudFront 缓存失效：白名单静态资源覆盖部署后调用。
 *
 * 版本化商品图/上传不需要失效（immutable + 唯一 key）。
 * 未配置 CLOUDFRONT_DISTRIBUTION_ID（本地开发）时静默跳过；
 * 失败只记日志不抛异常。
 */
class CloudFrontInvalidator
{
    /**
     * 对路径集合创建一条 CloudFront 失效（支持通配符，如 /brand/*）。
     */
    public function invalidate(array $paths): void
    {
        $paths = array_values(array_filter(array_map(
            fn (string $p) => str_starts_with($p, '/') ? $p : "/{$p}",
            $paths
        )));

        if (empty($paths)) {
            return;
        }

        $distributionId = (string) config('app.cloudfront_distribution_id');
        if ($distributionId === '') {
            return; // 本地开发，无失效目标
        }

        try {
            $client = new CloudFrontClient([
                'region'  => (string) config('filesystems.disks.image.region', 'us-east-1'),
                'version' => 'latest',
            ]);

            $client->createInvalidation([
                'DistributionId'    => $distributionId,
                'InvalidationBatch' => [
                    'CallerReference' => 'arel-' . uniqid(),
                    'Paths'           => [
                        'Quantity' => count($paths),
                        'Items'    => $paths,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('CloudFront 失效失败: ' . $e->getMessage(), ['paths' => $paths]);
        }
    }
}
