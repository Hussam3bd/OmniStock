<?php

namespace App\Filament\Exports;

use App\Models\Purchase\PurchaseOrder;
use Cknow\Money\Money;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class PurchaseOrderExporter extends Exporter
{
    protected static ?string $model = PurchaseOrder::class;

    public static function getColumns(): array
    {
        return [
            // ── Identity ──────────────────────────────────────────────────────
            ExportColumn::make('order_number')
                ->label('Order #'),

            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? $state),

            ExportColumn::make('order_date')
                ->label('Order Date'),

            ExportColumn::make('expected_delivery_date')
                ->label('Expected Delivery'),

            ExportColumn::make('received_date')
                ->label('Received Date'),

            // ── Parties ───────────────────────────────────────────────────────
            ExportColumn::make('supplier.name')
                ->label('Supplier'),

            ExportColumn::make('location.name')
                ->label('Location'),

            ExportColumn::make('account.name')
                ->label('Account')
                ->enabledByDefault(false),

            // ── Currency ──────────────────────────────────────────────────────
            ExportColumn::make('currency_code')
                ->label('Currency'),

            ExportColumn::make('exchange_rate')
                ->label('Exchange Rate (to TRY)')
                ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 4) : ''),

            // ── Financials ────────────────────────────────────────────────────
            ExportColumn::make('subtotal')
                ->label('Subtotal')
                ->state(fn (PurchaseOrder $record): string => self::money($record->subtotal)),

            ExportColumn::make('tax')
                ->label('Tax')
                ->state(fn (PurchaseOrder $record): string => self::money($record->tax)),

            ExportColumn::make('shipping_cost')
                ->label('Shipping Cost')
                ->state(fn (PurchaseOrder $record): string => self::money($record->shipping_cost)),

            ExportColumn::make('total')
                ->label('Total')
                ->state(fn (PurchaseOrder $record): string => self::money($record->total)),

            ExportColumn::make('total_try')
                ->label('Total (TRY)')
                ->state(fn (PurchaseOrder $record): string => $record->getTotalInDefaultCurrency() !== null
                    ? number_format((float) $record->getTotalInDefaultCurrency(), 2)
                    : ''),

            // ── Line item summary ─────────────────────────────────────────────
            ExportColumn::make('items_count')
                ->label('# SKUs')
                ->counts('items'),

            ExportColumn::make('total_units_ordered')
                ->label('Total Units Ordered')
                ->state(fn (PurchaseOrder $record): int => $record->items->sum('quantity_ordered')),

            ExportColumn::make('total_units_received')
                ->label('Total Units Received')
                ->state(fn (PurchaseOrder $record): int => $record->items->sum('quantity_received')),

            ExportColumn::make('avg_unit_cost')
                ->label('Avg Unit Cost')
                ->state(function (PurchaseOrder $record): string {
                    $items = $record->items;
                    $totalQty = $items->sum('quantity_ordered');

                    if ($totalQty === 0) {
                        return '';
                    }

                    $totalCost = $items->sum(fn ($i) => $i->unit_cost?->getAmount() ?? 0);

                    return number_format($totalCost / $totalQty / 100, 2);
                }),

            // ── Notes ─────────────────────────────────────────────────────────
            ExportColumn::make('notes')
                ->label('Notes')
                ->enabledByDefault(false),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Purchase order export completed. '
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

        // Raw integer minor units (e.g. direct DB value)
        if (is_int($value)) {
            return number_format($value / 100, 2);
        }

        return (string) $value;
    }
}
