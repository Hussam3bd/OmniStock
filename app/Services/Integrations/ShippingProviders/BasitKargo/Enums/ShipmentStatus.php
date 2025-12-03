<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\Enums;

enum ShipmentStatus: string
{
    case NEW = 'NEW';
    case READY_TO_SHIP = 'READY_TO_SHIP';
    case SHIPPED = 'SHIPPED';
    case OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    case COMPLETED = 'COMPLETED';
    case NEEDS_SUPPORT = 'NEEDS_SUPPORT';
    case DELAYED = 'DELAYED';
    case RETURNING = 'RETURNING';
    case RETURNED = 'RETURNED';
    case LOST = 'LOST';

    public function getLabel(): string
    {
        return match ($this) {
            self::NEW => __('New'),
            self::READY_TO_SHIP => __('Ready to Ship'),
            self::SHIPPED => __('Shipped'),
            self::OUT_FOR_DELIVERY => __('Out for Delivery'),
            self::COMPLETED => __('Delivered'),
            self::NEEDS_SUPPORT => __('Support Needed'),
            self::DELAYED => __('Delayed'),
            self::RETURNING => __('Returning'),
            self::RETURNED => __('Returned'),
            self::LOST => __('Lost'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NEW, self::READY_TO_SHIP => 'gray',
            self::SHIPPED, self::OUT_FOR_DELIVERY => 'info',
            self::COMPLETED => 'success',
            self::NEEDS_SUPPORT, self::LOST => 'danger',
            self::DELAYED => 'warning',
            self::RETURNING, self::RETURNED => 'warning',
        };
    }

    public function isDelivered(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isInTransit(): bool
    {
        return in_array($this, [self::SHIPPED, self::OUT_FOR_DELIVERY]);
    }

    public function isProblematic(): bool
    {
        return in_array($this, [self::NEEDS_SUPPORT, self::DELAYED, self::LOST]);
    }

    public function isReturned(): bool
    {
        return in_array($this, [self::RETURNING, self::RETURNED]);
    }
}
