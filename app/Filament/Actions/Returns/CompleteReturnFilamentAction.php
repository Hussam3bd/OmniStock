<?php

namespace App\Filament\Actions\Returns;

use App\Actions\Returns\CompleteReturnAction;
use App\Models\Order\OrderReturn;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class CompleteReturnFilamentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'complete_return';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Complete Return'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Complete Return'))
            ->modalDescription(__('Mark this return as completed. This will update the order status and finalize the return process.'))
            ->visible(fn (OrderReturn $record) => app(CompleteReturnAction::class)->validate($record))
            ->action(function (OrderReturn $record) {
                try {
                    $action = app(CompleteReturnAction::class);
                    $action->execute($record, ['user' => auth()->user()]);

                    Notification::make()
                        ->success()
                        ->title(__('Return Completed'))
                        ->body(__('The return has been completed successfully.'))
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Operation Failed'))
                        ->body(__('Failed to complete return: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }
}
