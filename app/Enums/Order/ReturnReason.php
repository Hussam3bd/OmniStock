<?php

namespace App\Enums\Order;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ReturnReason: string implements HasColor, HasIcon, HasLabel
{
    // Customer-initiated reasons
    case CHANGED_MIND = 'changed_mind';
    case WRONG_SIZE = 'wrong_size';
    case WRONG_COLOR = 'wrong_color';
    case WRONG_PRODUCT = 'wrong_product';
    case DEFECTIVE = 'defective';
    case NOT_AS_DESCRIBED = 'not_as_described';
    case POOR_QUALITY = 'poor_quality';
    case ARRIVED_LATE = 'arrived_late';
    case DAMAGED_IN_TRANSIT = 'damaged_in_transit';
    case MISSING_PARTS = 'missing_parts';
    case DUPLICATE_ORDER = 'duplicate_order';

    // Delivery-related reasons
    case COD_REJECTED = 'cod_rejected';
    case REFUSED_DELIVERY = 'refused_delivery';
    case ADDRESS_UNREACHABLE = 'address_unreachable';
    case CUSTOMER_NOT_AVAILABLE = 'customer_not_available';
    case CUSTOMER_CHANGED_ADDRESS = 'customer_changed_address';

    // Carrier/System reasons
    case RETURNED_BY_CARRIER = 'returned_by_carrier';
    case UNDELIVERABLE = 'undeliverable';
    case LOST_IN_TRANSIT = 'lost_in_transit';
    case INCORRECT_ADDRESS = 'incorrect_address';

    // Seller/Merchant reasons
    case OUT_OF_STOCK = 'out_of_stock';
    case CANCELLED_BY_SELLER = 'cancelled_by_seller';
    case PRICING_ERROR = 'pricing_error';

    // Other
    case OTHER = 'other';

    /**
     * Smart mapping from text to return reason enum
     * Supports fuzzy matching for variations across channels
     */
    public static function fromText(string $text): ?self
    {
        $text = strtolower(trim($text));

        // COD patterns (Turkish & English)
        if (str_contains($text, 'cod') ||
            str_contains($text, 'cash on delivery') ||
            str_contains($text, 'kabul etmedi') ||
            str_contains($text, 'teslim alamadı') ||
            str_contains($text, 'kapıda ödeme')) {
            return self::COD_REJECTED;
        }

        // Size patterns
        if (str_contains($text, 'size') ||
            str_contains($text, 'beden') ||
            str_contains($text, 'boyut')) {
            return self::WRONG_SIZE;
        }

        // Color patterns
        if (str_contains($text, 'color') ||
            str_contains($text, 'colour') ||
            str_contains($text, 'renk')) {
            return self::WRONG_COLOR;
        }

        // Defective/Broken patterns
        if (str_contains($text, 'defect') ||
            str_contains($text, 'broken') ||
            str_contains($text, 'faulty') ||
            str_contains($text, 'arızalı') ||
            str_contains($text, 'hasarlı') ||
            str_contains($text, 'bozuk')) {
            return self::DEFECTIVE;
        }

        // Damaged patterns
        if (str_contains($text, 'damage') ||
            str_contains($text, 'hasar')) {
            return self::DAMAGED_IN_TRANSIT;
        }

        // Quality patterns
        if (str_contains($text, 'quality') ||
            str_contains($text, 'kalite')) {
            return self::POOR_QUALITY;
        }

        // Changed mind patterns
        if (str_contains($text, 'changed mind') ||
            str_contains($text, 'fikir değiştir') ||
            str_contains($text, 'vazgeç')) {
            return self::CHANGED_MIND;
        }

        // Wrong product patterns
        if (str_contains($text, 'wrong product') ||
            str_contains($text, 'yanlış ürün') ||
            str_contains($text, 'farklı ürün')) {
            return self::WRONG_PRODUCT;
        }

        // Not as described patterns
        if (str_contains($text, 'not as described') ||
            str_contains($text, 'açıklamaya uymuyor') ||
            str_contains($text, 'farklı')) {
            return self::NOT_AS_DESCRIBED;
        }

        // Late delivery patterns
        if (str_contains($text, 'late') ||
            str_contains($text, 'geç') ||
            str_contains($text, 'gecikme')) {
            return self::ARRIVED_LATE;
        }

        // Missing parts patterns
        if (str_contains($text, 'missing') ||
            str_contains($text, 'incomplete') ||
            str_contains($text, 'eksik')) {
            return self::MISSING_PARTS;
        }

        // Address unreachable patterns
        if (str_contains($text, 'unreachable') ||
            str_contains($text, 'ulaşılamıyor') ||
            str_contains($text, 'adres bulunamadı')) {
            return self::ADDRESS_UNREACHABLE;
        }

        // Customer not available patterns
        if (str_contains($text, 'not available') ||
            str_contains($text, 'müsait değil') ||
            str_contains($text, 'evde değil')) {
            return self::CUSTOMER_NOT_AVAILABLE;
        }

        // Refused delivery patterns
        if (str_contains($text, 'refused') ||
            str_contains($text, 'reject') ||
            str_contains($text, 'reddetti')) {
            return self::REFUSED_DELIVERY;
        }

        // Out of stock patterns
        if (str_contains($text, 'out of stock') ||
            str_contains($text, 'stok') ||
            str_contains($text, 'stock')) {
            return self::OUT_OF_STOCK;
        }

        // Duplicate order patterns
        if (str_contains($text, 'duplicate') ||
            str_contains($text, 'çift sipariş')) {
            return self::DUPLICATE_ORDER;
        }

        // Carrier/undeliverable patterns
        if (str_contains($text, 'undeliverable') ||
            str_contains($text, 'returned by carrier') ||
            str_contains($text, 'carrier return') ||
            str_contains($text, 'kargo iade')) {
            return self::RETURNED_BY_CARRIER;
        }

        // Default to OTHER if no match
        return self::OTHER;
    }

    /**
     * Map Trendyol claim reason code to ReturnReason
     */
    public static function fromTrendyolCode(string $code): ?self
    {
        return match ($code) {
            // Common Trendyol codes (extend as needed)
            'SIZE_ISSUE' => self::WRONG_SIZE,
            'COLOR_ISSUE' => self::WRONG_COLOR,
            'DEFECTIVE_PRODUCT' => self::DEFECTIVE,
            'WRONG_PRODUCT' => self::WRONG_PRODUCT,
            'NOT_AS_DESCRIBED' => self::NOT_AS_DESCRIBED,
            'QUALITY_ISSUE' => self::POOR_QUALITY,
            'DAMAGED' => self::DAMAGED_IN_TRANSIT,
            'MISSING_PARTS' => self::MISSING_PARTS,
            'LATE_DELIVERY' => self::ARRIVED_LATE,
            'CHANGED_MIND' => self::CHANGED_MIND,
            default => null,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::CHANGED_MIND => __('Changed Mind'),
            self::WRONG_SIZE => __('Wrong Size'),
            self::WRONG_COLOR => __('Wrong Color'),
            self::WRONG_PRODUCT => __('Wrong Product'),
            self::DEFECTIVE => __('Defective Product'),
            self::NOT_AS_DESCRIBED => __('Not as Described'),
            self::POOR_QUALITY => __('Poor Quality'),
            self::ARRIVED_LATE => __('Arrived Late'),
            self::DAMAGED_IN_TRANSIT => __('Damaged in Transit'),
            self::MISSING_PARTS => __('Missing Parts'),
            self::DUPLICATE_ORDER => __('Duplicate Order'),
            self::COD_REJECTED => __('COD Rejected by Customer'),
            self::REFUSED_DELIVERY => __('Refused Delivery'),
            self::ADDRESS_UNREACHABLE => __('Address Unreachable'),
            self::CUSTOMER_NOT_AVAILABLE => __('Customer Not Available'),
            self::CUSTOMER_CHANGED_ADDRESS => __('Customer Changed Address'),
            self::RETURNED_BY_CARRIER => __('Returned by Carrier'),
            self::UNDELIVERABLE => __('Undeliverable'),
            self::LOST_IN_TRANSIT => __('Lost in Transit'),
            self::INCORRECT_ADDRESS => __('Incorrect Address'),
            self::OUT_OF_STOCK => __('Out of Stock'),
            self::CANCELLED_BY_SELLER => __('Cancelled by Seller'),
            self::PRICING_ERROR => __('Pricing Error'),
            self::OTHER => __('Other'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DEFECTIVE, self::DAMAGED_IN_TRANSIT, self::POOR_QUALITY => 'danger',
            self::WRONG_SIZE, self::WRONG_COLOR, self::WRONG_PRODUCT => 'warning',
            self::COD_REJECTED, self::REFUSED_DELIVERY => 'danger',
            self::CHANGED_MIND => 'info',
            self::OUT_OF_STOCK, self::CANCELLED_BY_SELLER => 'warning',
            self::RETURNED_BY_CARRIER, self::UNDELIVERABLE => 'gray',
            default => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::DEFECTIVE, self::DAMAGED_IN_TRANSIT => 'heroicon-o-exclamation-triangle',
            self::WRONG_SIZE, self::WRONG_COLOR, self::WRONG_PRODUCT => 'heroicon-o-arrow-path',
            self::COD_REJECTED, self::REFUSED_DELIVERY => 'heroicon-o-x-circle',
            self::CHANGED_MIND => 'heroicon-o-arrow-uturn-left',
            self::NOT_AS_DESCRIBED => 'heroicon-o-question-mark-circle',
            self::ARRIVED_LATE => 'heroicon-o-clock',
            self::MISSING_PARTS => 'heroicon-o-puzzle-piece',
            self::OUT_OF_STOCK => 'heroicon-o-archive-box-x-mark',
            default => 'heroicon-o-information-circle',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::CHANGED_MIND => __('Customer changed their mind about the purchase'),
            self::WRONG_SIZE => __('Product size does not fit'),
            self::WRONG_COLOR => __('Product color is not as expected'),
            self::WRONG_PRODUCT => __('Wrong product was delivered'),
            self::DEFECTIVE => __('Product has manufacturing defects'),
            self::NOT_AS_DESCRIBED => __('Product does not match description'),
            self::POOR_QUALITY => __('Product quality is below expectations'),
            self::ARRIVED_LATE => __('Delivery was significantly delayed'),
            self::DAMAGED_IN_TRANSIT => __('Product was damaged during shipping'),
            self::MISSING_PARTS => __('Product arrived with missing components'),
            self::DUPLICATE_ORDER => __('Customer accidentally ordered twice'),
            self::COD_REJECTED => __('Customer refused cash on delivery payment'),
            self::REFUSED_DELIVERY => __('Customer refused to accept delivery'),
            self::ADDRESS_UNREACHABLE => __('Delivery address could not be located'),
            self::CUSTOMER_NOT_AVAILABLE => __('Customer was not available for delivery'),
            self::CUSTOMER_CHANGED_ADDRESS => __('Customer changed delivery address'),
            self::RETURNED_BY_CARRIER => __('Shipment returned by carrier'),
            self::UNDELIVERABLE => __('Shipment could not be delivered'),
            self::LOST_IN_TRANSIT => __('Shipment was lost during transit'),
            self::INCORRECT_ADDRESS => __('Delivery address was incorrect'),
            self::OUT_OF_STOCK => __('Product is out of stock'),
            self::CANCELLED_BY_SELLER => __('Order cancelled by seller'),
            self::PRICING_ERROR => __('Product price was incorrect'),
            self::OTHER => __('Other reason'),
        };
    }

    /**
     * Check if this is a customer-fault reason (may incur restocking fee)
     */
    public function isCustomerFault(): bool
    {
        return in_array($this, [
            self::CHANGED_MIND,
            self::WRONG_SIZE,
            self::WRONG_COLOR,
            self::DUPLICATE_ORDER,
        ]);
    }

    /**
     * Check if this is a seller-fault reason (full refund required)
     */
    public function isSellerFault(): bool
    {
        return in_array($this, [
            self::DEFECTIVE,
            self::NOT_AS_DESCRIBED,
            self::POOR_QUALITY,
            self::WRONG_PRODUCT,
            self::DAMAGED_IN_TRANSIT,
            self::MISSING_PARTS,
            self::ARRIVED_LATE,
            self::OUT_OF_STOCK,
            self::CANCELLED_BY_SELLER,
            self::PRICING_ERROR,
        ]);
    }

    /**
     * Check if this is a delivery-related reason
     */
    public function isDeliveryIssue(): bool
    {
        return in_array($this, [
            self::COD_REJECTED,
            self::REFUSED_DELIVERY,
            self::ADDRESS_UNREACHABLE,
            self::CUSTOMER_NOT_AVAILABLE,
            self::CUSTOMER_CHANGED_ADDRESS,
            self::RETURNED_BY_CARRIER,
            self::UNDELIVERABLE,
            self::INCORRECT_ADDRESS,
        ]);
    }
}
