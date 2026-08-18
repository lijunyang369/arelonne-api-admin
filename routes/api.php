<?php

use Illuminate\Support\Facades\Route;

// =========================================================================
// API Admin — 后台 API（🇨🇳 部署）
// =========================================================================

Route::prefix('admin')->name('admin.')->group(function () {
    Route::post('login', [\App\Http\Controllers\Admin\AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        // Controller 无 show 方法,排除以免 GET /categories/{id} 500
        Route::apiResource('categories', \App\Http\Controllers\Admin\CategoryController::class)->except('show');
        Route::apiResource('products', \App\Http\Controllers\Admin\ProductController::class);
        Route::post('products/batch-import', [\App\Http\Controllers\Admin\ProductController::class, 'batchImport']);
        Route::apiResource('colors', \App\Http\Controllers\Admin\ColorController::class);
        Route::get('orders', [\App\Http\Controllers\Admin\OrderController::class, 'index']);
        Route::get('orders/{id}', [\App\Http\Controllers\Admin\OrderController::class, 'show']);
        Route::put('orders/{id}/status', [\App\Http\Controllers\Admin\OrderController::class, 'updateStatus']);
        Route::get('settings', [\App\Http\Controllers\Admin\SettingController::class, 'index']);
        Route::put('settings', [\App\Http\Controllers\Admin\SettingController::class, 'update']);
    });
});
