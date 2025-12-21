<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ChannelSalesBreakdown;
use App\Filament\Widgets\NetProfitByChannelChart;
use App\Filament\Widgets\OrdersByChannelChart;
use App\Filament\Widgets\OrdersOverTimeChart;
use App\Filament\Widgets\OrderStatsOverview;
use App\Filament\Widgets\ReturnsByChannelChart;
use App\Filament\Widgets\ReturnsStatsWidget;
use App\Filament\Widgets\RevenueByChannelChart;
use App\Filament\Widgets\RevenueChartWidget;
use BackedEnum;
use Filament\Pages\Page;

class OrdersReport extends Page
{
    protected string $view = 'filament.pages.orders-report';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('Reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('Orders Report');
    }

    public function getTitle(): string
    {
        return __('Orders Report');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OrderStatsOverview::class,
            ReturnsStatsWidget::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            ChannelSalesBreakdown::class,
            OrdersOverTimeChart::class,
            RevenueChartWidget::class,
            OrdersByChannelChart::class,
            RevenueByChannelChart::class,
            NetProfitByChannelChart::class,
            ReturnsByChannelChart::class,
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
