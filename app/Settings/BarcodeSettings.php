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
            'custom' => ['label' => __('Custom'), 'width' => 76, 'height' => 25.4],
            '76x25' => ['label' => '76 × 25.4 mm (Standard)', 'width' => 76, 'height' => 25.4],
            '50x25' => ['label' => '50 × 25 mm (Small)', 'width' => 50, 'height' => 25],
            '100x50' => ['label' => '100 × 50 mm (Large)', 'width' => 100, 'height' => 50],
            '70x35' => ['label' => '70 × 35 mm (Shoe Box)', 'width' => 70, 'height' => 35],
            '90x28' => ['label' => '90 × 28 mm (Shelf Label)', 'width' => 90, 'height' => 28],
        ];
    }
}
