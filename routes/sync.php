<?php

/**
 * 内部同步路由 — 仅供对端调用，不对外开放。
 *
 * 所有路由受 X-Sync-Key 头验证保护。
 */

use App\Http\Controllers\Sync\ColorSyncController;
use App\Http\Controllers\Sync\OrderSyncController;
use App\Http\Controllers\Sync\ProductSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('sync')->name('sync.')->middleware([
    App\Http\Middleware\VerifySyncKey::class,
])->group(function () {

    // 商品同步（🇨🇳 → 🇺🇸）
    Route::post('products', [ProductSyncController::class, 'store']);
    Route::delete('products/{id}', [ProductSyncController::class, 'destroy']);
    Route::post('products/{id}/variants', [ProductSyncController::class, 'syncVariants']);

    // 颜色同步（🇨🇳 → 🇺🇸）
    Route::post('colors', [ColorSyncController::class, 'store']);
    Route::put('colors/{id}', [ColorSyncController::class, 'update']);
    Route::delete('colors/{id}', [ColorSyncController::class, 'destroy']);

    // 订单同步（🇺🇸 → 🇨🇳）
    Route::post('orders', [OrderSyncController::class, 'store']);
    Route::put('orders/{id}/status', [OrderSyncController::class, 'updateStatus']);
});
