<?php

namespace App\Filament\Resources\Purchase\PurchaseOrders;

use App\Filament\Resources\Purchase\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\Purchase\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\Purchase\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\Purchase\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Filament\Resources\Purchase\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\Purchase\PurchaseOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('Purchases');
    }

    public static function getModelLabel(): string
    {
        return __('Purchase Order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Purchase Orders');
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
