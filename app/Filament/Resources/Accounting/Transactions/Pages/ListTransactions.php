<?php

namespace App\Filament\Resources\Accounting\Transactions\Pages;

use App\Enums\Accounting\TransactionType;
use App\Filament\Imports\TransactionImporter;
use App\Filament\Resources\Accounting\Transactions\TransactionResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            ImportAction::make('import_transactions')
                ->label(__('Import Transactions'))
                ->color('primary')
                ->importer(TransactionImporter::class),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All'))
                ->badge(fn () => \App\Models\Accounting\Transaction::count()),

            'income' => Tab::make(__('Income'))
                ->badge(fn () => \App\Models\Accounting\Transaction::where('type', TransactionType::INCOME)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', TransactionType::INCOME)),

            'expenses' => Tab::make(__('Expenses'))
                ->badge(fn () => \App\Models\Accounting\Transaction::where('type', TransactionType::EXPENSE)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', TransactionType::EXPENSE)),
        ];
    }
}
