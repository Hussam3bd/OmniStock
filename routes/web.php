<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('filament.admin.pages.dashboard');
});

Route::webhooks('webhooks/ty', 'trendyol');
Route::webhooks('webhooks/shopify', 'shopify');
Route::webhooks('webhooks/basitkargo', 'basitkargo');
