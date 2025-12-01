<?php

namespace App\Enums\Order;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReturnStatus: string implements HasColor, HasLabel
{
    case Requested = 'requested';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case LabelGenerated = 'label_generated';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Inspecting = 'inspecting';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::PendingReview => 'Pending Review',
            self::Approved => 'Approved',
            self::LabelGenerated => 'Label Generated',
            self::InTransit => 'In Transit',
            self::Received => 'Received',
            self::Inspecting => 'Inspecting',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested, self::PendingReview => 'warning',
            self::Approved, self::LabelGenerated => 'info',
            self::InTransit, self::Received, self::Inspecting => 'primary',
            self::Completed => 'success',
            self::Rejected, self::Cancelled => 'danger',
        };
    }

    public function isPending(): bool
    {
        return in_array($this, [
            self::Requested,
            self::PendingReview,
            self::Approved,
            self::LabelGenerated,
            self::InTransit,
            self::Received,
            self::Inspecting,
        ]);
    }

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    public function isRejected(): bool
    {
        return $this === self::Rejected;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Rejected,
            self::Cancelled,
        ]);
    }
}
