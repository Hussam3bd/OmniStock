<?php

namespace App\Filament\Resources\Customer\Customers;

use App\Filament\Resources\Customer\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customer\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customer\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customer\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customer\Customers\Tables\CustomersTable;
use App\Models\Customer\Customer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('Sales');
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AddressesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
