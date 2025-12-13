<?php

namespace App\Filament\Resources\Accounting\Expenses\Pages;

use App\Filament\Resources\Accounting\Expenses\ExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Add Expense'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
