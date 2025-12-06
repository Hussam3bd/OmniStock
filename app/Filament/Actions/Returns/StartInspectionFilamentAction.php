<?php

namespace App\Filament\Actions\Returns;

use App\Actions\Returns\StartInspectionAction;
use App\Models\Order\OrderReturn;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class StartInspectionFilamentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'start_return_inspection';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Start Inspection'))
            ->icon('heroicon-o-magnifying-glass')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Start Return Inspection'))
            ->modalDescription(__('Begin inspecting the returned items to verify their condition.'))
            ->visible(fn (OrderReturn $record) => app(StartInspectionAction::class)->validate($record))
            ->action(function (OrderReturn $record) {
                try {
                    $action = app(StartInspectionAction::class);
                    $action->execute($record, ['user' => auth()->user()]);

                    Notification::make()
                        ->success()
                        ->title(__('Inspection Started'))
                        ->body(__('Return inspection has been started.'))
                        ->send();

                    // Redirect to refresh the page
                    $this->redirect(request()->url());
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Operation Failed'))
                        ->body(__('Failed to start inspection: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }
}
