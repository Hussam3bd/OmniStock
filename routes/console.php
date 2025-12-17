<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('exchange-rates:update')
    ->everyFourHours()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('products:update-variant-costs')
    ->dailyAt('10:00')
    ->onOneServer()
    ->withoutOverlapping();
