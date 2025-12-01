<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::webhooks('webhooks/trendyol', 'trendyol')->name('webhooks.trendyol');
