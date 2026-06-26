<?php

/**
 * 服务角色配置。
 *
 * APP_ROLE 环境变量决定当前实例加载哪些路由和能力：
 *   - store：前台 API（美国 AWS），含内部 /api/sync/* 接收对端同步
 *   - admin：后台 API（中国阿里云），写操作后向 store 推送同步
 *   - both：本地开发两套路由都加载
 */
return [
    'role' => env('APP_ROLE', 'both'),

    // 对端 API 地址（用于同步推送）
    'sync' => [
        'store' => env('SYNC_STORE_URL', 'http://us-api.internal:8081'),
        'admin' => env('SYNC_ADMIN_URL', 'http://cn-api.internal:8081'),
    ],
];
