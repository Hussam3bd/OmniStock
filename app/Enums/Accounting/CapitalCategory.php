<?php

namespace App\Enums\Accounting;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CapitalCategory: string implements HasColor, HasLabel
{
    case OWNER_CONTRIBUTION = 'owner_contribution';
    case OWNER_WITHDRAWAL = 'owner_withdrawal';
    case PROFIT_DISTRIBUTION = 'profit_distribution';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::OWNER_CONTRIBUTION => __('Owner Contribution'),
            self::OWNER_WITHDRAWAL => __('Owner Withdrawal'),
            self::PROFIT_DISTRIBUTION => __('Profit Distribution'),
            self::OTHER => __('Other Capital Transaction'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::OWNER_CONTRIBUTION => 'success',
            self::OWNER_WITHDRAWAL => 'danger',
            self::PROFIT_DISTRIBUTION => 'info',
            self::OTHER => 'gray',
        };
    }
}
