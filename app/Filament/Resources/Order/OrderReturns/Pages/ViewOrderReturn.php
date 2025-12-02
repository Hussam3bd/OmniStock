<?php

namespace App\Filament\Resources\Order\OrderReturns\Pages;

use App\Enums\Order\ReturnStatus;
use App\Filament\Resources\Order\OrderReturns\Infolists\OrderReturnInfolist;
use App\Filament\Resources\Order\OrderReturns\OrderReturnResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewOrderReturn extends ViewRecord
{
    protected static string $resource = OrderReturnResource::class;

    public function infolist(Schema $schema): Schema
    {
        return OrderReturnInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve Return')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn ($record) => $record->canApprove())
                ->action(function ($record) {
                    $record->approve(auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Return Approved')
                        ->body('The return has been approved successfully.')
                        ->send();
                }),

            Action::make('reject')
                ->label('Reject Return')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn ($record) => $record->canReject())
                ->form([
                    Textarea::make('reason')
                        ->label('Rejection Reason')
                        ->required()
                        ->rows(3),
                ])
                ->action(function ($record, array $data) {
                    $record->reject(auth()->user(), $data['reason']);

                    Notification::make()
                        ->success()
                        ->title('Return Rejected')
                        ->body('The return has been rejected.')
                        ->send();
                }),

            Action::make('mark_received')
                ->label('Mark as Received')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('info')
                ->requiresConfirmation()
                ->visible(fn ($record) => $record->status === ReturnStatus::InTransit)
                ->action(function ($record) {
                    $record->markAsReceived(auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Return Received')
                        ->body('The return has been marked as received.')
                        ->send();
                }),

            Action::make('start_inspection')
                ->label('Start Inspection')
                ->icon('heroicon-o-magnifying-glass')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn ($record) => $record->status === ReturnStatus::Received)
                ->action(function ($record) {
                    $record->startInspection(auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Inspection Started')
                        ->body('Return inspection has been started.')
                        ->send();
                }),

            Action::make('complete')
                ->label('Complete Return')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn ($record) => in_array($record->status, [ReturnStatus::Received, ReturnStatus::Inspecting]))
                ->action(function ($record) {
                    $record->complete(auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Return Completed')
                        ->body('The return has been completed successfully.')
                        ->send();
                }),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            \App\Filament\Resources\Order\OrderReturns\RelationManagers\RefundsRelationManager::class,
        ];
    }
}
