<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('filament.admin.pages.dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/admin/orders/{order}/shipping-label', [\App\Http\Controllers\ShippingLabelController::class, 'show'])
        ->name('admin.orders.shipping-label');

    Route::get('/admin/orders/{order}/trendyol-label', [\App\Http\Controllers\TrendyolLabelController::class, 'show'])
        ->name('admin.orders.trendyol-label');

    Route::get('/admin/orders/{order}/basitkargo-label', [\App\Http\Controllers\BasitKargoLabelController::class, 'show'])
        ->name('admin.orders.basitkargo-label');

    Route::get('/admin/orders/bulk-labels', [\App\Http\Controllers\BulkShippingLabelsController::class, 'show'])
        ->name('admin.orders.bulk-labels');

    Route::get('/admin/barcode-labels/print', [\App\Http\Controllers\BarcodeLabelsController::class, 'print'])
        ->name('admin.barcode-labels.print');
});

Route::webhooks('webhooks/ty', 'trendyol');
Route::webhooks('webhooks/shopify', 'shopify');
Route::webhooks('webhooks/basitkargo', 'basitkargo');
