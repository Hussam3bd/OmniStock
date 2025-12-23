<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class SmsSettings extends Settings
{
    public string $awaiting_pickup_template;

    public static function group(): string
    {
        return 'sms';
    }
}
