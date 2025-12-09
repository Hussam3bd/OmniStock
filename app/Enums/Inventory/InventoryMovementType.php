<?php

namespace App\Enums\Inventory;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InventoryMovementType: string implements HasColor, HasLabel
{
    case Sale = 'sale';
    case Return = 'return';
    case Cancellation = 'cancellation';
    case Adjustment = 'adjustment';
    case PurchaseReceived = 'purchase_received';
    case Damaged = 'damaged';
    case Transfer = 'transfer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sale => 'Sale (Order Created)',
            self::Return => 'Return (Completed)',
            self::Cancellation => 'Cancellation',
            self::Adjustment => 'Manual Adjustment',
            self::PurchaseReceived => 'Purchase Received',
            self::Damaged => 'Damaged',
            self::Transfer => 'Transfer Between Locations',
        };
    }

    public function isDeduction(): bool
    {
        return in_array($this, [
            self::Sale,
            self::Damaged,
        ]);
    }

    public function isAddition(): bool
    {
        return in_array($this, [
            self::Return,
            self::Cancellation,
            self::PurchaseReceived,
        ]);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Sale, self::Damaged => 'danger',
            self::PurchaseReceived => 'success',
            self::Return => 'info',
            self::Adjustment => 'warning',
            self::Cancellation => 'primary',
            default => 'gray',
        };
    }
}
