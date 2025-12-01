<?php

namespace App\Filament\Resources\Order\OrderReturns;

use App\Filament\Resources\Order\OrderReturns\Pages\CreateOrderReturn;
use App\Filament\Resources\Order\OrderReturns\Pages\ListOrderReturns;
use App\Filament\Resources\Order\OrderReturns\Pages\ViewOrderReturn;
use App\Filament\Resources\Order\OrderReturns\Schemas\OrderReturnForm;
use App\Filament\Resources\Order\OrderReturns\Tables\OrderReturnsTable;
use App\Models\Order\OrderReturn;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrderReturnResource extends Resource
{
    protected static ?string $model = OrderReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?string $navigationLabel = 'Returns';

    protected static ?string $modelLabel = 'Return';

    protected static ?string $pluralModelLabel = 'Returns';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('Sales');
    }

    public static function form(Schema $schema): Schema
    {
        return OrderReturnForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrderReturnsTable::configure($table);
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
            'index' => ListOrderReturns::route('/'),
            'create' => CreateOrderReturn::route('/create'),
            'view' => ViewOrderReturn::route('/{record}'),
        ];
    }
}
