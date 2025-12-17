<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::webhooks('webhooks/ty', 'trendyol');
Route::webhooks('webhooks/shopify', 'shopify');
Route::webhooks('webhooks/basitkargo', 'basitkargo');
