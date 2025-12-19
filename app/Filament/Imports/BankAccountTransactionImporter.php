<?php

namespace App\Filament\Imports;

use App\Enums\Accounting\ImportSourceType;
use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\Account;
use App\Models\Accounting\ImportedTransaction;
use App\Models\Accounting\Transaction;
use App\Models\Accounting\TransactionCategoryMapping;
use App\Support\NumberParser;
use Carbon\Carbon;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class BankAccountTransactionImporter extends Importer
{
    protected static ?string $model = Transaction::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('date')
                ->label('Date')
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['Tarih', 'Date', 'tarih', 'date']),

            ImportColumn::make('description')
                ->label('Description')
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['Açıklama', 'Description', 'açıklama', 'description']),

            ImportColumn::make('label')
                ->label('Label')
                ->guess(['Etiket', 'Label', 'etiket', 'label']),

            ImportColumn::make('amount')
                ->label('Amount')
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['Tutar', 'Amount', 'tutar', 'amount', 'Miktar', 'miktar']),

            ImportColumn::make('reference')
                ->label('Reference No')
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['Dekont No', 'Reference No', 'Reference', 'dekont no', 'reference']),
        ];
    }

    public function resolveRecord(): ?Transaction
    {
        // Ensure account ID is provided in options
        $accountId = $this->options['accountId'] ?? null;
        if (! $accountId) {
            return null;
        }

        // Parse transaction date
        $transactionDate = $this->parseDate($this->data['date']);
        if (! $transactionDate) {
            return null;
        }

        // Parse amount (handles both Turkish and English formats)
        $amount = NumberParser::parseAmount($this->data['amount']);
        if ($amount === null) {
            return null;
        }

        // Check if already imported using Dekont No (external reference)
        $reference = $this->data['reference'];
        if (ImportedTransaction::existsByReference($accountId, $reference)) {
            return null;
        }

        // Get account
        $account = Account::find($accountId);
        if (! $account) {
            return null;
        }

        // Determine transaction type based on amount (positive = income, negative = expense)
        $type = $amount > 0 ? TransactionType::INCOME : TransactionType::EXPENSE;
        $absoluteAmount = abs($amount);

        // Auto-categorize based on description
        $category = $this->autoCategorize($this->data['description'], $type, $accountId);

        // Generate hash for additional deduplication layer
        $hash = ImportedTransaction::generateHash(
            $transactionDate->format('Y-m-d'),
            $amount,
            $this->data['description']
        );

        // Create transaction
        $transaction = Transaction::create([
            'account_id' => $accountId,
            'type' => $type,
            'category' => $category,
            'amount' => $absoluteAmount, // Money cast will convert to minor units automatically
            'currency_code' => $account->currency->code,
            'currency_id' => $account->currency_id,
            'description' => $this->data['description'],
            'transaction_date' => $transactionDate,
        ]);

        // Create imported transaction record
        ImportedTransaction::create([
            'source_type' => ImportSourceType::BANK_ACCOUNT,
            'account_id' => $accountId,
            'external_reference' => $reference,
            'transaction_hash' => $hash,
            'transaction_id' => $transaction->id,
            'imported_at' => now(),
        ]);

        return $transaction;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your bank account transaction import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }

    /**
     * Parse Turkish date format (DD/MM/YYYY)
     */
    protected function parseDate(string $date): ?Carbon
    {
        try {
            return Carbon::createFromFormat('d/m/Y', $date)->startOfDay();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Auto-categorize transaction based on description patterns
     */
    protected function autoCategorize(string $description, TransactionType $type, int $accountId): ?string
    {
        $mappings = TransactionCategoryMapping::getActiveMappings($accountId);

        foreach ($mappings as $mapping) {
            // Check if mapping type matches transaction type
            if ($mapping->type->value !== $type->value) {
                continue;
            }

            // Check if pattern matches description
            if ($mapping->matches($description)) {
                return $mapping->category;
            }
        }

        // Return null if no mapping found (will need manual categorization)
        return null;
    }
}
