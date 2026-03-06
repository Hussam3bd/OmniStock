<?php

use App\Jobs\SyncMissingPaymentTransactionIds;
use Illuminate\Support\Facades\Schedule;

Schedule::command('exchange-rates:update')
    ->everyFourHours()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('products:update-variant-costs')
    ->dailyAt('10:00')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('activitylog:clean')
    ->weekly()
    ->onOneServer()
    ->withoutOverlapping();

// Auto-sync missing payment transaction IDs for recent Shopify orders
Schedule::job(new SyncMissingPaymentTransactionIds(50))
    ->daily()
    ->onOneServer()
    ->withoutOverlapping();

// Auto-sync Trendyol return claims using a 2-day window to avoid fetching all historical data
Schedule::command('trendyol:sync-claims --days=2')
    ->twiceDaily(6, 18)
    ->onOneServer()
    ->withoutOverlapping();
