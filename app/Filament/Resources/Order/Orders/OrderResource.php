<?php

namespace App\Filament\Resources\Order\Orders;

use App\Filament\Resources\Order\Orders\Pages\CreateOrder;
use App\Filament\Resources\Order\Orders\Pages\EditOrder;
use App\Filament\Resources\Order\Orders\Pages\ListOrders;
use App\Filament\Resources\Order\Orders\Pages\ViewOrder;
use App\Filament\Resources\Order\Orders\Schemas\OrderForm;
use App\Filament\Resources\Order\Orders\Tables\OrdersTable;
use App\Models\Order\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('Sales');
    }

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AddressesRelationManager::class,
            RelationManagers\ItemsRelationManager::class,
            RelationManagers\ReturnsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
