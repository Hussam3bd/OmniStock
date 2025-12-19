<?php

namespace App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings\Pages;

use App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings\TransactionCategoryMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTransactionCategoryMapping extends EditRecord
{
    protected static string $resource = TransactionCategoryMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
