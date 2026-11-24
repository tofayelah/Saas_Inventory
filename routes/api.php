<?php

use App\Http\Controllers\Api\V1\TenantApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Legacy /user endpoint
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Version 1 Multi-Tenant Secure API
Route::prefix('v1')->middleware(['auth:sanctum', 'tenant.api'])->group(function () {
    // 1. Context & Token Introspection
    Route::get('/auth/context', [TenantApiController::class, 'tokenContext'])->name('api.v1.auth.context');

    // 2. Product Catalog API (Requires token ability + tenant permission)
    Route::get('/products', [TenantApiController::class, 'products'])
        ->middleware('token.ability:products:read')
        ->name('api.v1.products.index');

    Route::post('/products', [TenantApiController::class, 'storeProduct'])
        ->middleware('token.ability:products:write')
        ->name('api.v1.products.store');

    // 3. Customers API
    Route::get('/customers', [TenantApiController::class, 'customers'])
        ->middleware('token.ability:customers:read')
        ->name('api.v1.customers.index');

    // 4. Sales Orders API
    Route::get('/orders', [TenantApiController::class, 'salesOrders'])
        ->middleware('token.ability:orders:read')
        ->name('api.v1.orders.index');

    // 5. Secure File Download API (Requires documents:read ability + tenant permission)
    Route::get('/files/download', [TenantApiController::class, 'downloadFile'])
        ->middleware('token.ability:documents:read')
        ->name('api.v1.files.download');
});
