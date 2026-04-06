<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BarcodeSettings extends Settings
{
    public string $barcode_format;

    public string $barcode_country_code;

    public ?string $barcode_company_prefix;

    public bool $barcode_auto_generate;

    public string $label_preset;

    public float $label_width;

    public float $label_height;

    public static function group(): string
    {
        return 'barcode';
    }

    /**
     * @return array<string, array{label: string, width: float, height: float}>
     */
    public static function labelPresets(): array
    {
        return [
            '60x40' => ['label' => '60 × 40 mm (Roll)', 'width' => 60, 'height' => 40],
        ];
    }
}
