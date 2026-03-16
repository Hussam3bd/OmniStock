<?php

namespace App\Filament\Exports;

use App\Models\Order\Order;
use Cknow\Money\Money;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class OrderExporter extends Exporter
{
    protected static ?string $model = Order::class;

    public static function getColumns(): array
    {
        return [
            // ── Identity ──────────────────────────────────────────────────────
            ExportColumn::make('order_number')
                ->label('Order #'),

            ExportColumn::make('order_date')
                ->label('Order Date'),

            ExportColumn::make('channel')
                ->label('Channel')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? $state),

            // ── Status ────────────────────────────────────────────────────────
            ExportColumn::make('order_status')
                ->label('Order Status')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? $state),

            ExportColumn::make('payment_status')
                ->label('Payment Status')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? $state),

            ExportColumn::make('fulfillment_status')
                ->label('Fulfillment Status')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? $state),

            // ── Customer ──────────────────────────────────────────────────────
            ExportColumn::make('customer.full_name')
                ->label('Customer Name'),

            ExportColumn::make('customer.email')
                ->label('Customer Email'),

            ExportColumn::make('customer.phone')
                ->label('Customer Phone')
                ->enabledByDefault(false),

            // ── Shipping address ──────────────────────────────────────────────
            ExportColumn::make('shippingAddress.province.name')
                ->label('Province'),

            ExportColumn::make('shippingAddress.district.name')
                ->label('District')
                ->enabledByDefault(false),

            // ── Revenue ───────────────────────────────────────────────────────
            ExportColumn::make('subtotal')
                ->label('Subtotal')
                ->state(fn (Order $record): string => self::money($record->subtotal)),

            ExportColumn::make('discount_amount')
                ->label('Discount')
                ->state(fn (Order $record): string => self::money($record->discount_amount)),

            ExportColumn::make('tax_amount')
                ->label('Tax')
                ->state(fn (Order $record): string => self::money($record->tax_amount)),

            ExportColumn::make('shipping_amount')
                ->label('Shipping Charged to Customer')
                ->state(fn (Order $record): string => self::money($record->shipping_amount)),

            ExportColumn::make('total_amount')
                ->label('Total Revenue')
                ->state(fn (Order $record): string => self::money($record->total_amount)),

            // ── Cost of goods ─────────────────────────────────────────────────
            ExportColumn::make('total_product_cost')
                ->label('Product Cost (COGS)')
                ->state(fn (Order $record): string => self::money($record->total_product_cost)),

            ExportColumn::make('effective_product_cost')
                ->label('Effective COGS (after returns)')
                ->state(fn (Order $record): string => self::money($record->effective_product_cost)),

            // ── Shipping costs ────────────────────────────────────────────────
            ExportColumn::make('shipping_cost_excluding_vat')
                ->label('Shipping Cost (excl. VAT)')
                ->state(fn (Order $record): string => self::money($record->shipping_cost_excluding_vat)),

            ExportColumn::make('shipping_vat_amount')
                ->label('Shipping VAT')
                ->state(fn (Order $record): string => self::money($record->shipping_vat_amount)),

            ExportColumn::make('total_shipping_cost')
                ->label('Total Shipping Cost (incl. VAT)')
                ->state(fn (Order $record): string => self::money($record->total_shipping_cost)),

            ExportColumn::make('shipping_carrier')
                ->label('Carrier')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? $state)
                ->enabledByDefault(false),

            // ── Platform commission ───────────────────────────────────────────
            ExportColumn::make('total_commission')
                ->label('Platform Commission')
                ->state(fn (Order $record): string => self::money($record->total_commission)),

            // ── Payment gateway ───────────────────────────────────────────────
            ExportColumn::make('payment_gateway')
                ->label('Payment Gateway')
                ->enabledByDefault(false),

            ExportColumn::make('payment_gateway_fee')
                ->label('Payment Gateway Fixed Fee')
                ->state(fn (Order $record): string => self::money($record->payment_gateway_fee))
                ->enabledByDefault(false),

            ExportColumn::make('payment_gateway_commission_rate')
                ->label('Payment Gateway Commission %')
                ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 4).'%' : '')
                ->enabledByDefault(false),

            ExportColumn::make('payment_gateway_commission_amount')
                ->label('Payment Gateway Commission Amount')
                ->state(fn (Order $record): string => self::money($record->payment_gateway_commission_amount))
                ->enabledByDefault(false),

            ExportColumn::make('total_payment_gateway_cost')
                ->label('Total Payment Gateway Cost')
                ->state(fn (Order $record): string => self::money($record->total_payment_gateway_cost)),

            ExportColumn::make('payment_payout_amount')
                ->label('Net Payout Amount')
                ->state(fn (Order $record): string => self::money($record->payment_payout_amount)),

            // ── Profitability ─────────────────────────────────────────────────
            ExportColumn::make('gross_profit')
                ->label('Gross Profit')
                ->state(fn (Order $record): string => self::money($record->gross_profit)),

            ExportColumn::make('gross_margin_pct')
                ->label('Gross Margin %')
                ->state(function (Order $record): string {
                    $revenue = $record->total_amount?->getAmount();
                    $profit = $record->gross_profit?->getAmount();

                    if (! $revenue || $revenue == 0) {
                        return '';
                    }

                    return number_format($profit / $revenue * 100, 2).'%';
                }),

            // ── Currency ──────────────────────────────────────────────────────
            ExportColumn::make('currency')
                ->label('Currency')
                ->enabledByDefault(false),

            ExportColumn::make('exchange_rate')
                ->label('Exchange Rate (to TRY)')
                ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 4) : '')
                ->enabledByDefault(false),

            // ── SKUs ──────────────────────────────────────────────────────────
            ExportColumn::make('item_skus')
                ->label('SKU(s)')
                ->state(fn (Order $record): string => $record->items
                    ->map(fn ($item) => $item->productVariant?->sku
                        ? ($item->quantity > 1
                            ? $item->productVariant->sku.' x'.$item->quantity
                            : $item->productVariant->sku)
                        : null
                    )
                    ->filter()
                    ->implode(', ') ?: ''
                ),

            ExportColumn::make('item_skus_expanded')
                ->label('SKU(s) Expanded')
                ->state(fn (Order $record): string => $record->items
                    ->flatMap(fn ($item) => $item->productVariant?->sku
                        ? array_fill(0, $item->quantity, $item->productVariant->sku)
                        : []
                    )
                    ->implode(', ') ?: ''
                )
                ->enabledByDefault(false),

            // ── Line items ────────────────────────────────────────────────────
            ExportColumn::make('items_count')
                ->label('# Line Items')
                ->counts('items'),

            ExportColumn::make('total_units')
                ->label('Total Units')
                ->state(fn (Order $record): int => $record->items->sum('quantity')),

            ExportColumn::make('items_summary')
                ->label('Products')
                ->state(function (Order $record): string {
                    return $record->items
                        ->map(fn ($item) => sprintf(
                            '%s × %d @ %s',
                            $item->productVariant?->product?->title ?? $item->productVariant?->sku ?? '?',
                            $item->quantity,
                            self::money($item->unit_price)
                        ))
                        ->implode('; ');
                })
                ->enabledByDefault(false),

            // ── Tracking ──────────────────────────────────────────────────────
            ExportColumn::make('shipping_tracking_number')
                ->label('Tracking Number')
                ->enabledByDefault(false),

            ExportColumn::make('invoice_number')
                ->label('Invoice #')
                ->enabledByDefault(false),

            ExportColumn::make('invoice_date')
                ->label('Invoice Date')
                ->enabledByDefault(false),

            ExportColumn::make('shipped_at')
                ->label('Shipped At')
                ->enabledByDefault(false),

            ExportColumn::make('delivered_at')
                ->label('Delivered At')
                ->enabledByDefault(false),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Order export completed. '
            .Number::format($export->successful_rows).' '
            .str('row')->plural($export->successful_rows).' exported.';

        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' failed.';
        }

        return $body;
    }

    private static function money(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        // Cknow\Money\Money — getAmount() returns minor units as string
        if ($value instanceof Money) {
            return number_format((int) $value->getAmount() / 100, 2);
        }

        // Raw integer minor units
        if (is_int($value)) {
            return number_format($value / 100, 2);
        }

        return (string) $value;
    }
}
