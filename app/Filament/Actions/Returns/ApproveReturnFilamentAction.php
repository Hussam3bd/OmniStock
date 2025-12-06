<?php

namespace App\Filament\Actions\Returns;

use App\Actions\Returns\ApproveReturnAction;
use App\Models\Order\OrderReturn;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ApproveReturnFilamentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approve_return';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Approve Return'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Approve Return'))
            ->modalDescription(__('Are you sure you want to approve this return? The customer will be able to generate a return shipping label.'))
            ->visible(fn (OrderReturn $record) => app(ApproveReturnAction::class)->validate($record))
            ->action(function (OrderReturn $record) {
                try {
                    $action = app(ApproveReturnAction::class);
                    $action->execute($record, ['user' => auth()->user()]);

                    Notification::make()
                        ->success()
                        ->title(__('Return Approved'))
                        ->body(__('The return has been approved successfully.'))
                        ->send();

                    // Redirect to refresh the page
                    $this->redirect(request()->url());
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Approval Failed'))
                        ->body(__('Failed to approve return: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }
}
