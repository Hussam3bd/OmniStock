<?php

namespace App\Filament\Widgets\Accounting;

use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\IncomeCategory;
use App\Models\Accounting\Transaction;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class CategoryBreakdownWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.accounting.category-breakdown-widget';

    public function getCategoryBreakdown(): array
    {
        // Get date range from page filters or default to current month
        $startDate = isset($this->pageFilters['startDate'])
            ? Carbon::parse($this->pageFilters['startDate'])->startOfDay()
            : now()->startOfMonth();

        $endDate = isset($this->pageFilters['endDate'])
            ? Carbon::parse($this->pageFilters['endDate'])->endOfDay()
            : now()->endOfMonth();

        return Transaction::query()
            ->with('currency')
            ->where('is_internal_transfer', false)
            ->whereNotNull('category')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->select('type', 'category', 'currency_code', DB::raw('SUM(amount) as total'))
            ->groupBy('type', 'category', 'currency_code')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => $item->type->getLabel(),
                    'category' => $item->category ? IncomeCategory::tryFrom($item->category)?->getLabel()
                        ?? ExpenseCategory::tryFrom($item->category)?->getLabel()
                        ?? $item->category : 'Uncategorized',
                    'amount' => money($item->total, $item->currency_code)->format(),
                    'amount_raw' => $item->total,
                ];
            })
            ->toArray();
    }
}
