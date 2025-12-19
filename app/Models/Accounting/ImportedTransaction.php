<?php

namespace App\Models\Accounting;

use App\Enums\Accounting\ImportSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportedTransaction extends Model
{
    protected $fillable = [
        'source_type',
        'account_id',
        'external_reference',
        'transaction_hash',
        'transaction_id',
        'imported_at',
    ];

    protected $casts = [
        'source_type' => ImportSourceType::class,
        'imported_at' => 'datetime',
    ];

    /**
     * Get the account
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the transaction
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Generate transaction hash for deduplication
     */
    public static function generateHash(string $date, float $amount, string $description): string
    {
        return md5($date.'|'.$amount.'|'.$description);
    }

    /**
     * Check if transaction was already imported
     */
    public static function exists(int $accountId, string $hash): bool
    {
        return static::where('account_id', $accountId)
            ->where('transaction_hash', $hash)
            ->exists();
    }

    /**
     * Check if transaction with external reference exists
     */
    public static function existsByReference(int $accountId, string $reference): bool
    {
        return static::where('account_id', $accountId)
            ->where('external_reference', $reference)
            ->exists();
    }
}
