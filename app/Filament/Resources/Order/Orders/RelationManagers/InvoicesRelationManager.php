<?php

namespace App\Filament\Resources\Order\Orders\RelationManagers;

use App\Filament\Resources\Invoice\Invoices\Tables\InvoicesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Invoices';

    public function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }
}
