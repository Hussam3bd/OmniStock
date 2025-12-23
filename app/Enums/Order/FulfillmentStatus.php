<?php

namespace App\Enums\Order;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum FulfillmentStatus: string implements HasColor, HasIcon, HasLabel
{
    case UNFULFILLED = 'unfulfilled';
    case ON_HOLD = 'on_hold';
    case AWAITING_SHIPMENT = 'awaiting_shipment';
    case PARTIALLY_FULFILLED = 'partially_fulfilled';
    case FULFILLED = 'fulfilled';
    case IN_TRANSIT = 'in_transit';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case AWAITING_PICKUP_AT_DISTRIBUTION_CENTER = 'awaiting_pickup_at_distribution_center';
    case DELIVERED = 'delivered';
    case RETURNED = 'returned';
    case CANCELLED = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::UNFULFILLED => __('Unfulfilled'),
            self::ON_HOLD => __('On Hold'),
            self::AWAITING_SHIPMENT => __('Awaiting Shipment'),
            self::PARTIALLY_FULFILLED => __('Partially Fulfilled'),
            self::FULFILLED => __('Fulfilled'),
            self::IN_TRANSIT => __('In Transit'),
            self::OUT_FOR_DELIVERY => __('Out for Delivery'),
            self::AWAITING_PICKUP_AT_DISTRIBUTION_CENTER => __('Awaiting Pickup at Distribution Center'),
            self::DELIVERED => __('Delivered'),
            self::RETURNED => __('Returned'),
            self::CANCELLED => __('Cancelled'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::UNFULFILLED => 'gray',
            self::ON_HOLD => 'warning',
            self::AWAITING_SHIPMENT => 'info',
            self::PARTIALLY_FULFILLED => 'warning',
            self::FULFILLED => 'success',
            self::IN_TRANSIT => 'primary',
            self::OUT_FOR_DELIVERY => 'primary',
            self::AWAITING_PICKUP_AT_DISTRIBUTION_CENTER => 'warning',
            self::DELIVERED => 'success',
            self::RETURNED => 'danger',
            self::CANCELLED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::UNFULFILLED => 'heroicon-o-inbox',
            self::ON_HOLD => 'heroicon-o-pause-circle',
            self::AWAITING_SHIPMENT => 'heroicon-o-cube',
            self::PARTIALLY_FULFILLED => 'heroicon-o-archive-box',
            self::FULFILLED => 'heroicon-o-check-circle',
            self::IN_TRANSIT => 'heroicon-o-truck',
            self::OUT_FOR_DELIVERY => 'heroicon-o-map-pin',
            self::AWAITING_PICKUP_AT_DISTRIBUTION_CENTER => 'heroicon-o-building-storefront',
            self::DELIVERED => 'heroicon-o-check-badge',
            self::RETURNED => 'heroicon-o-arrow-uturn-left',
            self::CANCELLED => 'heroicon-o-x-circle',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::UNFULFILLED => __('Order has not been fulfilled yet'),
            self::ON_HOLD => __('Fulfillment is temporarily on hold'),
            self::AWAITING_SHIPMENT => __('Items packaged, awaiting shipment'),
            self::PARTIALLY_FULFILLED => __('Some items shipped, others pending'),
            self::FULFILLED => __('All items have been shipped'),
            self::IN_TRANSIT => __('Order is in transit to customer'),
            self::OUT_FOR_DELIVERY => __('Order is out for delivery'),
            self::AWAITING_PICKUP_AT_DISTRIBUTION_CENTER => __('Awaiting customer pickup at distribution center'),
            self::DELIVERED => __('Order successfully delivered'),
            self::RETURNED => __('Order has been returned'),
            self::CANCELLED => __('Fulfillment cancelled'),
        };
    }
}
