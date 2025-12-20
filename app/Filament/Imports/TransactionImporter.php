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
use Filament\Forms\Components\Select;

class TransactionImporter extends Importer
{
    protected static ?string $model = Transaction::class;

    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('accountId')
                ->label('Account')
                ->required()
                ->searchable()
                ->options(Account::query()->pluck('name', 'id'))
                ->helperText('Select the account these transactions belong to.'),

            Select::make('sourceType')
                ->label('Source Type')
                ->required()
                ->options([
                    ImportSourceType::BANK_ACCOUNT->value => 'Bank Account',
                    ImportSourceType::CREDIT_CARD->value => 'Credit Card',
                    ImportSourceType::MANUAL->value => 'Manual Entry',
                ])
                ->default(ImportSourceType::BANK_ACCOUNT->value)
                ->helperText('Select the type of transactions you are importing.'),
        ];
    }

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('date')
                ->label('Date')
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['Tarih', 'Date', 'tarih', 'date'])
                ->example('18/12/2025'),

            ImportColumn::make('description')
                ->label('Description')
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['Açıklama', 'Description', 'açıklama', 'description', 'İşlem'])
                ->example('Payment to vendor'),

            ImportColumn::make('amount')
                ->label('Amount')
                ->requiredMapping()
                ->rules(['required'])
                ->guess(['Tutar', 'Amount', 'tutar', 'amount', 'Miktar', 'miktar', 'Tutar(TL)'])
                ->example('1,234.56'),

            ImportColumn::make('reference')
                ->label('Reference No')
                ->guess(['Dekont No', 'Reference No', 'Reference', 'dekont no', 'reference'])
                ->example('REF-123456'),

            ImportColumn::make('label')
                ->label('Label')
                ->guess(['Etiket', 'Label', 'etiket', 'label'])
                ->example('Business Expense'),
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

        // Generate hash for deduplication
        $hash = ImportedTransaction::generateHash(
            $transactionDate->format('Y-m-d'),
            $amount,
            $this->data['description']
        );

        // Check if already imported
        if (ImportedTransaction::exists($accountId, $hash)) {
            return null;
        }

        // Get account
        $account = Account::find($accountId);
        if (! $account) {
            return null;
        }

        // Determine transaction type based on amount (negative = expense, positive = income)
        $type = $amount > 0 ? TransactionType::INCOME : TransactionType::EXPENSE;
        $absoluteAmount = abs($amount);

        // Auto-categorize based on description
        $category = $this->autoCategorize($this->data['description'], $type, $accountId);

        // Create transaction
        $transaction = Transaction::create([
            'account_id' => $accountId,
            'type' => $type,
            'category' => $category,
            'amount' => $absoluteAmount,
            'currency_code' => $account->currency->code,
            'currency_id' => $account->currency_id,
            'description' => $this->data['description'],
            'transaction_date' => $transactionDate,
        ]);

        // Get source type from options or default to manual
        $sourceType = isset($this->options['sourceType'])
            ? ImportSourceType::from($this->options['sourceType'])
            : ImportSourceType::MANUAL;

        // Create imported transaction record
        ImportedTransaction::create([
            'source_type' => $sourceType,
            'account_id' => $accountId,
            'external_reference' => $this->data['reference'] ?? null,
            'transaction_hash' => $hash,
            'transaction_id' => $transaction->id,
            'imported_at' => now(),
        ]);

        return $transaction;
    }

    public function saveRecord(): void
    {
        // Record is already saved in resolveRecord(), so we don't need to save it again
        if ($this->record->exists) {
            return;
        }

        parent::saveRecord();
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your transaction import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }

    /**
     * Parse date in multiple formats (DD/MM/YYYY, YYYY-MM-DD, etc.)
     */
    protected function parseDate(string $date): ?Carbon
    {
        // Try Turkish format first (DD/MM/YYYY)
        try {
            return Carbon::createFromFormat('d/m/Y', $date)->startOfDay();
        } catch (\Exception $e) {
            // Try other common formats
            try {
                return Carbon::parse($date)->startOfDay();
            } catch (\Exception $e) {
                return null;
            }
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
