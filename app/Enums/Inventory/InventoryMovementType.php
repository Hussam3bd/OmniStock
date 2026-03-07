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
    case Lost = 'lost';
    case NeedsFix = 'needs_fix';
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
            self::Lost => 'Lost',
            self::NeedsFix => 'Needs Fix',
            self::Transfer => 'Transfer Between Locations',
        };
    }

    public function isDeduction(): bool
    {
        return in_array($this, [
            self::Sale,
            self::Damaged,
            self::Lost,
            self::NeedsFix,
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

    /**
     * Whether items of this type are still physically present in the warehouse.
     * Damaged and NeedsFix items are still on the shelf; Lost items are gone.
     */
    public function isPhysicallyPresent(): bool
    {
        return in_array($this, [self::Damaged, self::NeedsFix]);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Sale, self::Damaged, self::Lost => 'danger',
            self::PurchaseReceived => 'success',
            self::Return => 'info',
            self::Adjustment => 'warning',
            self::Cancellation => 'primary',
            self::NeedsFix => 'warning',
            default => 'gray',
        };
    }
}
