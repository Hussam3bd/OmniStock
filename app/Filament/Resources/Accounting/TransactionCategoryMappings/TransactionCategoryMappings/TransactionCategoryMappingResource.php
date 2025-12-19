<?php

namespace App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings;

use App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings\Pages\CreateTransactionCategoryMapping;
use App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings\Pages\EditTransactionCategoryMapping;
use App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings\Pages\ListTransactionCategoryMappings;
use App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings\Schemas\TransactionCategoryMappingForm;
use App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings\Tables\TransactionCategoryMappingsTable;
use App\Models\Accounting\TransactionCategoryMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TransactionCategoryMappingResource extends Resource
{
    protected static ?string $model = TransactionCategoryMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('Accounting');
    }

    public static function getModelLabel(): string
    {
        return __('Category Mapping');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Category Mappings');
    }

    public static function form(Schema $schema): Schema
    {
        return TransactionCategoryMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransactionCategoryMappingsTable::configure($table);
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
            'index' => ListTransactionCategoryMappings::route('/'),
            'create' => CreateTransactionCategoryMapping::route('/create'),
            'edit' => EditTransactionCategoryMapping::route('/{record}/edit'),
        ];
    }
}
