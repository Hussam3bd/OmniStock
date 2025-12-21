<?php

namespace App\Filament\Resources\Accounting\Transactions\Tables;

use App\Enums\Accounting\CapitalCategory;
use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\IncomeCategory;
use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\Transaction;
use App\Models\Accounting\TransactionCategoryMapping;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('transaction_date', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('transaction_date')
                    ->label(__('Date'))
                    ->date()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('type')
                    ->badge()
                    ->sortable()
                    ->searchable(),

                SelectColumn::make('category')
                    ->label(__('Category'))
                    ->options(function (Transaction $record) {
                        return [
                            '' => '-- '.__('Uncategorized'),
                        ] + static::getCategoryOptions($record->type);
                    })
                    ->selectablePlaceholder(false)
                    ->afterStateUpdated(function (Transaction $record, $state) {
                        // Auto-create category mapping when user manually categorizes
                        static::createCategoryMapping($record, $state);
                    })
                    ->sortable()
                    ->searchable(),

                TextColumn::make('account.name')
                    ->label(__('Account'))
                    ->badge()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (Transaction $record) => $record->getDualCurrencyDisplay())
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('Description'))
                    ->limit(50)
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('transactionable.order_number')
                    ->label(__('Related'))
                    ->formatStateUsing(function ($record, $state) {
                        if (! $state) {
                            return '-';
                        }
                        $type = class_basename($record->transactionable_type);

                        return "{$type}: {$state}";
                    })
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('currency')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_internal_transfer')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options(TransactionType::class),

                SelectFilter::make('category')
                    ->label(__('Category'))
                    ->searchable()
                    ->options(function () {
                        $incomeOptions = collect(IncomeCategory::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()]);
                        $expenseOptions = collect(ExpenseCategory::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()]);

                        return $incomeOptions->merge($expenseOptions)->toArray();
                    })
                    ->multiple(),

                SelectFilter::make('account_id')
                    ->label(__('Account'))
                    ->relationship('account', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ReplicateAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Get category options based on transaction type
     */
    protected static function getCategoryOptions(TransactionType $type): array
    {
        return match ($type) {
            TransactionType::INCOME => collect(IncomeCategory::cases())
                ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                ->toArray(),
            TransactionType::EXPENSE => collect(ExpenseCategory::cases())
                ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                ->toArray(),
            TransactionType::CAPITAL => collect(CapitalCategory::cases())
                ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                ->toArray(),
        };
    }

    /**
     * Create category mapping from manual categorization
     */
    protected static function createCategoryMapping(Transaction $record, ?string $category): void
    {
        if (! $category || ! $record->description) {
            return;
        }

        // Only create mappings for imported transactions (not order/purchase order transactions)
        if ($record->transactionable_type) {
            return;
        }

        // Extract a clean pattern from description (first meaningful word or phrase)
        $pattern = static::extractPattern($record->description);

        if (! $pattern) {
            return;
        }

        // Check if a similar mapping already exists
        $existingMapping = TransactionCategoryMapping::where('pattern', $pattern)
            ->where('type', $record->type)
            ->where('category', $category)
            ->first();

        if ($existingMapping) {
            return; // Mapping already exists
        }

        // Get the highest priority (lowest number) to place this new rule at top
        $highestPriority = TransactionCategoryMapping::min('priority') ?? 0;
        $newPriority = $highestPriority > 0 ? 0 : $highestPriority - 1;

        // Create the mapping
        TransactionCategoryMapping::create([
            'pattern' => $pattern,
            'type' => $record->type,
            'category' => $category,
            'account_id' => $record->account_id,
            'is_active' => true,
            'priority' => $newPriority,
        ]);

        // Notify user
        Notification::make()
            ->title(__('Category Mapping Created'))
            ->body(__('Auto-categorization rule created for pattern: :pattern', ['pattern' => $pattern]))
            ->success()
            ->send();
    }

    /**
     * Extract a meaningful pattern from transaction description
     *
     * Uses the entire description to create specific, non-generic mappings
     */
    protected static function extractPattern(string $description): ?string
    {
        // Clean the description
        $cleaned = trim($description);

        if (empty($cleaned)) {
            return null;
        }

        // Use entire description (up to 255 chars to fit in DB)
        // This ensures specific mappings that won't accidentally match unrelated transactions
        $pattern = mb_strlen($cleaned) > 255
            ? mb_substr($cleaned, 0, 255)
            : $cleaned;

        return mb_strtoupper($pattern);
    }
}
