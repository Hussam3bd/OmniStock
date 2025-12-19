<?php

namespace App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings\Pages;

use App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings\TransactionCategoryMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTransactionCategoryMappings extends ListRecords
{
    protected static string $resource = TransactionCategoryMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
