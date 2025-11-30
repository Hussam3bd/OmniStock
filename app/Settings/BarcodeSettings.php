<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BarcodeSettings extends Settings
{
    public string $barcode_format;

    public string $barcode_country_code;

    public ?string $barcode_company_prefix;

    public bool $barcode_auto_generate;

    public static function group(): string
    {
        return 'barcode';
    }
}
