<?php

namespace App\Filament\Actions\Returns;

use App\Actions\Returns\MarkAsReceivedAction;
use App\Models\Order\OrderReturn;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class MarkAsReceivedFilamentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'mark_return_as_received';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Mark as Received'))
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('Mark Return as Received'))
            ->modalDescription(__('Confirm that the return shipment has been received at the warehouse.'))
            ->visible(fn (OrderReturn $record) => app(MarkAsReceivedAction::class)->validate($record))
            ->action(function (OrderReturn $record) {
                try {
                    $action = app(MarkAsReceivedAction::class);
                    $action->execute($record, ['user' => auth()->user()]);

                    Notification::make()
                        ->success()
                        ->title(__('Return Received'))
                        ->body(__('The return has been marked as received.'))
                        ->send();

                    // Redirect to refresh the page
                    $this->redirect(request()->url());
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Operation Failed'))
                        ->body(__('Failed to mark return as received: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }
}
