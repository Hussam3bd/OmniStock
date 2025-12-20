<?php

namespace App\Filament\Widgets\Accounting;

use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class FinancialStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Get date range from page filters or default to current month
        $startDate = isset($this->pageFilters['startDate'])
            ? \Carbon\Carbon::parse($this->pageFilters['startDate'])->startOfDay()
            : now()->startOfMonth();

        $endDate = isset($this->pageFilters['endDate'])
            ? \Carbon\Carbon::parse($this->pageFilters['endDate'])->endOfDay()
            : now()->endOfMonth();

        // Business Performance (excludes internal transfers)
        $businessIncome = Transaction::where('type', TransactionType::INCOME)
            ->where('is_internal_transfer', false)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount');

        $businessExpenses = Transaction::where('type', TransactionType::EXPENSE)
            ->where('is_internal_transfer', false)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount');

        $businessProfit = $businessIncome - $businessExpenses;

        // Total Cash Position
        $totalCash = Account::all()->sum(fn ($account) => $account->balance->getAmount());

        // Internal Transfers this month
        $internalTransfers = Transaction::where('is_internal_transfer', true)
            ->where('type', TransactionType::EXPENSE) // Count only one side
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount');

        // Uncategorized transactions
        $uncategorized = Transaction::where('is_internal_transfer', false)
            ->whereNull('category')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->count();

        $dateRangeDescription = $startDate->format('M d') === now()->startOfMonth()->format('M d') &&
                                $endDate->format('M d') === now()->endOfMonth()->format('M d')
            ? __('This month (excludes transfers)')
            : $startDate->format('M d, Y').' - '.$endDate->format('M d, Y');

        return [
            Stat::make(__('Business Income'), money($businessIncome, 'TRY')->format())
                ->description($dateRangeDescription)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->chart($this->getIncomeTrendData()),

            Stat::make(__('Business Expenses'), money($businessExpenses, 'TRY')->format())
                ->description($dateRangeDescription)
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color('danger')
                ->chart($this->getExpensesTrendData()),

            Stat::make(__('Business Profit'), money($businessProfit, 'TRY')->format())
                ->description(__('Income - Expenses'))
                ->descriptionIcon($businessProfit >= 0 ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle')
                ->color($businessProfit >= 0 ? 'success' : 'danger'),

            Stat::make(__('Total Cash Position'), money($totalCash, 'TRY')->format())
                ->description(__('Across all accounts'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('info'),

            Stat::make(__('Internal Transfers'), money($internalTransfers, 'TRY')->format())
                ->description(__('Money moved between accounts'))
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color('gray'),

            Stat::make(__('Uncategorized'), number_format($uncategorized))
                ->description(__('Transactions need categorization'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($uncategorized > 0 ? 'warning' : 'success'),
        ];
    }

    protected function getIncomeTrendData(): array
    {
        // Get income for last 7 days
        return Transaction::where('type', TransactionType::INCOME)
            ->where('is_internal_transfer', false)
            ->where('transaction_date', '>=', now()->subDays(7))
            ->select(DB::raw('DATE(transaction_date) as date'), DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date')
            ->map(fn ($value) => $value / 100)
            ->toArray();
    }

    protected function getExpensesTrendData(): array
    {
        // Get expenses for last 7 days
        return Transaction::where('type', TransactionType::EXPENSE)
            ->where('is_internal_transfer', false)
            ->where('transaction_date', '>=', now()->subDays(7))
            ->select(DB::raw('DATE(transaction_date) as date'), DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date')
            ->map(fn ($value) => $value / 100)
            ->toArray();
    }
}
