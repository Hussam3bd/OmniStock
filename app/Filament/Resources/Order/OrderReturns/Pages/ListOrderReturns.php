<?php

namespace App\Filament\Resources\Order\OrderReturns\Pages;

use App\Enums\Order\ReturnStatus;
use App\Filament\Resources\Order\OrderReturns\OrderReturnResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrderReturns extends ListRecords
{
    protected static string $resource = OrderReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Returns can only be created through integrations (Trendyol, Shopify, etc.)
        ];
    }

    public function getTabs(): array
    {
        return [
            'needs_review' => Tab::make(__('Needs Review'))
                ->badge(fn () => OrderReturnResource::getEloquentQuery()
                    ->whereIn('status', [
                        ReturnStatus::PendingReview,
                        ReturnStatus::Approved,
                        ReturnStatus::Received,
                        ReturnStatus::Inspecting,
                    ])
                    ->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    ReturnStatus::PendingReview,
                    ReturnStatus::Approved,
                    ReturnStatus::Received,
                    ReturnStatus::Inspecting,
                ])),

            'all' => Tab::make(__('All'))
                ->badge(fn () => OrderReturnResource::getEloquentQuery()->count()),

            'pending_review' => Tab::make(__('Pending Review'))
                ->badge(fn () => OrderReturnResource::getEloquentQuery()
                    ->where('status', ReturnStatus::PendingReview)
                    ->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReturnStatus::PendingReview)),

            'ready_to_process' => Tab::make(__('Ready to Process'))
                ->badge(fn () => OrderReturnResource::getEloquentQuery()
                    ->where('status', ReturnStatus::Approved)
                    ->count())
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReturnStatus::Approved)),

            'in_warehouse' => Tab::make(__('In Warehouse'))
                ->badge(fn () => OrderReturnResource::getEloquentQuery()
                    ->whereIn('status', [ReturnStatus::Received, ReturnStatus::Inspecting])
                    ->count())
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    ReturnStatus::Received,
                    ReturnStatus::Inspecting,
                ])),

            'awaiting_shipment' => Tab::make(__('Awaiting Shipment'))
                ->badge(fn () => OrderReturnResource::getEloquentQuery()
                    ->whereIn('status', [
                        ReturnStatus::Requested,
                        ReturnStatus::LabelGenerated,
                        ReturnStatus::InTransit,
                    ])
                    ->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    ReturnStatus::Requested,
                    ReturnStatus::LabelGenerated,
                    ReturnStatus::InTransit,
                ])),

            'completed' => Tab::make(__('Completed'))
                ->badge(fn () => OrderReturnResource::getEloquentQuery()
                    ->whereIn('status', [
                        ReturnStatus::Completed,
                        ReturnStatus::Rejected,
                        ReturnStatus::Cancelled,
                    ])
                    ->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    ReturnStatus::Completed,
                    ReturnStatus::Rejected,
                    ReturnStatus::Cancelled,
                ])),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'needs_review';
    }
}
