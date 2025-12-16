<?php

namespace App\Models\Purchase;

use App\Enums\PurchaseOrderStatus;
use App\Models\Accounting\Account;
use App\Models\Concerns\HasCurrencyCode;
use App\Models\Currency;
use App\Models\Inventory\Location;
use App\Models\Supplier\Supplier;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasCurrencyCode;

    protected $fillable = [
        'order_number',
        'supplier_id',
        'account_id',
        'location_id',
        'currency_id',
        'currency_code',
        'exchange_rate',
        'status',
        'order_date',
        'expected_delivery_date',
        'received_date',
        'subtotal',
        'tax',
        'shipping_cost',
        'total',
        'notes',
    ];

    protected $with = ['currency'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'received_date' => 'date',
            'exchange_rate' => 'decimal:8',
            'subtotal' => MoneyIntegerCast::class.':currency_code',
            'tax' => MoneyIntegerCast::class.':currency_code',
            'shipping_cost' => MoneyIntegerCast::class.':currency_code',
            'total' => MoneyIntegerCast::class.':currency_code',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Get the total in default currency
     */
    public function getTotalInDefaultCurrency(): float
    {
        if (! $this->exchange_rate || ! $this->total) {
            return 0;
        }

        return $this->total->getAmount() * $this->exchange_rate / 100;
    }
}
