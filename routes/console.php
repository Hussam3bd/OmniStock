<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('exchange-rates:update')
    ->everyFourHours()
    ->onOneServer()
    ->withoutOverlapping();
