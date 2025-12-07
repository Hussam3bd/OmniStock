<?php

namespace App\Filament\Actions\Returns;

use App\Actions\Returns\RejectReturnAction;
use App\Models\Order\OrderReturn;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

class RejectReturnFilamentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'reject_return';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Reject Return'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Reject Return'))
            ->modalDescription(__('Are you sure you want to reject this return? Please provide a reason for rejection.'))
            ->visible(fn (OrderReturn $record) => app(RejectReturnAction::class)->validate($record))
            ->form([
                Textarea::make('reason')
                    ->label(__('Rejection Reason'))
                    ->required()
                    ->rows(3)
                    ->placeholder(__('Enter the reason for rejecting this return...')),
            ])
            ->action(function (OrderReturn $record, array $data) {
                try {
                    $action = app(RejectReturnAction::class);
                    $action->execute($record, [
                        'user' => auth()->user(),
                        'reason' => $data['reason'],
                    ]);

                    Notification::make()
                        ->success()
                        ->title(__('Return Rejected'))
                        ->body(__('The return has been rejected.'))
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Rejection Failed'))
                        ->body(__('Failed to reject return: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }
}
