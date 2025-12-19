<?php

namespace App\Models\Accounting;

use App\Enums\Accounting\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionCategoryMapping extends Model
{
    protected $fillable = [
        'pattern',
        'category',
        'type',
        'account_id',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * Get the account this mapping is specific to (optional)
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Check if a description matches this pattern
     */
    public function matches(string $description): bool
    {
        return str_contains(strtoupper($description), strtoupper($this->pattern));
    }

    /**
     * Get active mappings ordered by priority
     */
    public static function getActiveMappings(?int $accountId = null)
    {
        return static::where('is_active', true)
            ->when($accountId, fn ($query) => $query->where(function ($q) use ($accountId) {
                $q->whereNull('account_id')->orWhere('account_id', $accountId);
            }))
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * Get category as enum based on transaction type
     */
    public function getCategoryEnumAttribute(): \App\Enums\Accounting\ExpenseCategory|\App\Enums\Accounting\IncomeCategory|\App\Enums\Accounting\CapitalCategory|null
    {
        if (! $this->category) {
            return null;
        }

        return match ($this->type) {
            \App\Enums\Accounting\TransactionType::INCOME => \App\Enums\Accounting\IncomeCategory::tryFrom($this->category),
            \App\Enums\Accounting\TransactionType::EXPENSE => \App\Enums\Accounting\ExpenseCategory::tryFrom($this->category),
            \App\Enums\Accounting\TransactionType::CAPITAL => \App\Enums\Accounting\CapitalCategory::tryFrom($this->category),
            default => null,
        };
    }
}
