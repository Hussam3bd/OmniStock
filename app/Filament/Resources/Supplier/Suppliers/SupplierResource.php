<?php

namespace App\Filament\Resources\Supplier\Suppliers;

use App\Filament\Resources\Supplier\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Supplier\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Supplier\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Supplier\Suppliers\Schemas\SupplierForm;
use App\Filament\Resources\Supplier\Suppliers\Tables\SuppliersTable;
use App\Models\Supplier\Supplier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('Purchases');
    }

    public static function getModelLabel(): string
    {
        return __('Supplier');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Suppliers');
    }

    public static function form(Schema $schema): Schema
    {
        return SupplierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliersTable::configure($table);
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
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
