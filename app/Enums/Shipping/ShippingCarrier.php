<?php

namespace App\Enums\Shipping;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ShippingCarrier: string implements HasColor, HasLabel
{
    case ARAS = 'aras';
    case DHL = 'dhl';
    case KOLAY_GELSIN = 'kolay_gelsin';
    case PTT = 'ptt';
    case SURAT = 'surat';
    case TEX = 'tex';
    case YURTICI = 'yurtici';
    case BORUSAN = 'borusan';
    case CEVA = 'ceva';
    case HOROZ = 'horoz';

    public function getLabel(): string
    {
        return match ($this) {
            self::ARAS => 'Aras',
            self::DHL => 'DHL eCommerce',
            self::KOLAY_GELSIN => 'Kolay Gelsin',
            self::PTT => 'PTT',
            self::SURAT => 'Sürat',
            self::TEX => 'TEX',
            self::YURTICI => 'Yurtiçi',
            self::BORUSAN => 'Borusan',
            self::CEVA => 'CEVA',
            self::HOROZ => 'Horoz',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::ARAS => 'orange',
            self::DHL => 'yellow',
            self::KOLAY_GELSIN => 'blue',
            self::PTT => 'red',
            self::SURAT => 'green',
            self::TEX => 'purple',
            self::YURTICI => 'indigo',
            self::BORUSAN => 'gray',
            self::CEVA => 'teal',
            self::HOROZ => 'pink',
        };
    }

    /**
     * Parse carrier name from various formats (e.g., "Aras Kargo", "ARAS", "aras")
     */
    public static function fromString(string $carrierName): ?self
    {
        $normalized = strtolower(trim($carrierName));

        // Direct matches
        foreach (self::cases() as $carrier) {
            if ($carrier->value === $normalized) {
                return $carrier;
            }
        }

        // Fuzzy matching for common variations
        return match (true) {
            str_contains($normalized, 'aras') => self::ARAS,
            str_contains($normalized, 'dhl') => self::DHL,
            str_contains($normalized, 'kolay') => self::KOLAY_GELSIN,
            str_contains($normalized, 'ptt') => self::PTT,
            str_contains($normalized, 'sürat') || str_contains($normalized, 'surat') => self::SURAT,
            str_contains($normalized, 'tex') => self::TEX,
            str_contains($normalized, 'yurtiçi') || str_contains($normalized, 'yurtici') => self::YURTICI,
            str_contains($normalized, 'borusan') => self::BORUSAN,
            str_contains($normalized, 'ceva') => self::CEVA,
            str_contains($normalized, 'horoz') => self::HOROZ,
            default => null,
        };
    }
}
