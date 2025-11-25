<?php

namespace App\Filament\Resources\Accounting\Transactions\Pages;

use App\Filament\Resources\Accounting\Transactions\TransactionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransaction extends CreateRecord
{
    protected static string $resource = TransactionResource::class;
}
