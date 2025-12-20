<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Accounting\AccountBalancesWidget;
use App\Filament\Widgets\Accounting\CategoryBreakdownWidget;
use App\Filament\Widgets\Accounting\FinancialStatsOverview;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard\Actions\FilterAction;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;
use Filament\Pages\Page;

class FinancialReports extends Page
{
    use HasFiltersAction;

    protected string $view = 'filament.pages.financial-reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 3;

    public function mount(): void
    {
        $this->filters = [
            'startDate' => now()->startOfMonth()->format('Y-m-d'),
            'endDate' => now()->endOfMonth()->format('Y-m-d'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('Financial Reports');
    }

    public function getTitle(): string
    {
        return __('Financial Reports');
    }

    protected function getHeaderActions(): array
    {
        return [
            FilterAction::make('filter')
                ->label(__('Filter by Date'))
                ->icon('heroicon-o-funnel')
                ->schema([
                    DatePicker::make('startDate')
                        ->label(__('Start Date'))
                        ->required()
                        ->native(false)
                        ->default(now()->startOfMonth()),

                    DatePicker::make('endDate')
                        ->label(__('End Date'))
                        ->required()
                        ->native(false)
                        ->default(now()->endOfMonth()),
                ]),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            FinancialStatsOverview::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            AccountBalancesWidget::class,
            CategoryBreakdownWidget::class,
        ];
    }

    public function getWidgets(): array
    {
        return [
            ...parent::getHeaderWidgets(),
            ...parent::getFooterWidgets(),
        ];
    }
}
