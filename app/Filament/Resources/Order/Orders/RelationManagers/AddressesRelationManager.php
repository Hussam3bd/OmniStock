<?php

namespace App\Filament\Resources\Order\Orders\RelationManagers;

use App\Filament\Resources\Address\Addresses\Schemas\AddressForm;
use App\Filament\Resources\Address\Addresses\Tables\AddressesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    protected static ?string $recordTitleAttribute = 'full_name';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return AddressForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return AddressesTable::configure($table)
            ->paginated(false);
    }
}
