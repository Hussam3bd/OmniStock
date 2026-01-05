<?php

namespace App\Filament\Resources\Invoice\Invoices;

use App\Filament\Resources\Invoice\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoice\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoice\Invoices\Tables\InvoicesTable;
use App\Models\Invoice\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('Sales');
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
