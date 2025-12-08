<?php

namespace App\Filament\Resources\Customer\Customers\RelationManagers;

use App\Filament\Resources\Address\Addresses\Schemas\AddressForm;
use App\Filament\Resources\Address\Addresses\Tables\AddressesTable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    public function form(Schema $schema): Schema
    {
        return AddressForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return AddressesTable::configure($table)
            ->headerActions([
                CreateAction::make()
                    ->modalWidth(Width::FourExtraLarge),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
