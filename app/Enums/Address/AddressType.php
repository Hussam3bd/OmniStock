<?php

namespace App\Enums\Address;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AddressType: string implements HasColor, HasIcon, HasLabel
{
    case RESIDENTIAL = 'residential';
    case INSTITUTIONAL = 'institutional';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::RESIDENTIAL => __('Residential'),
            self::INSTITUTIONAL => __('Institutional (Kurumsal)'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::RESIDENTIAL => 'info',
            self::INSTITUTIONAL => 'warning',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::RESIDENTIAL => 'heroicon-o-home',
            self::INSTITUTIONAL => 'heroicon-o-building-office',
        };
    }
}
