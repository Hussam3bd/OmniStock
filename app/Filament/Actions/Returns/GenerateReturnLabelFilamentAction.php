<?php

namespace App\Filament\Actions\Returns;

use App\Actions\Returns\GenerateReturnLabelAction;
use App\Models\Order\OrderReturn;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class GenerateReturnLabelFilamentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'generate_return_label';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Generate Return Label'))
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('Generate Return Label'))
            ->modalDescription(fn (OrderReturn $record) => __('This will create a return shipment via :carrier and generate a shipping label. The customer will be able to use this label to return their items.', [
                'carrier' => $record->order->shipping_aggregator_integration->name ?? 'shipping provider',
            ]))
            ->visible(fn (OrderReturn $record) => app(GenerateReturnLabelAction::class)->validate($record))
            ->action(function (OrderReturn $record) {
                try {
                    $action = app(GenerateReturnLabelAction::class);
                    $action->execute($record, [
                        'save_label_file' => true,
                    ]);

                    Notification::make()
                        ->success()
                        ->title(__('Label Generated'))
                        ->body(__('Return shipping label has been generated successfully. Tracking number: :tracking', [
                            'tracking' => $record->return_tracking_number,
                        ]))
                        ->send();

                    // Redirect to refresh the page
                    $this->redirect(request()->url());
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Label Generation Failed'))
                        ->body(__('Failed to generate return label: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }
}
