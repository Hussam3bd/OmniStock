<?php

namespace App\Filament\Actions\Order;

use App\Actions\Invoice\GenerateInvoiceAction as GenerateInvoiceActionHandler;
use App\Enums\Integration\IntegrationType;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;

class GenerateInvoiceAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'generate_invoice';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Generate Invoice'))
            ->icon('heroicon-o-document-text')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Generate Legal Invoice'))
            ->modalDescription(__('Generate an e-archive invoice for this order using your configured invoice provider.'))
            ->schema(function (Order $record) {
                $providers = Integration::where('type', IntegrationType::INVOICE_PROVIDER)
                    ->where('is_active', true)
                    ->pluck('name', 'id');

                if ($providers->isEmpty()) {
                    return [];
                }

                return [
                    Select::make('integration_id')
                        ->label(__('Invoice Provider'))
                        ->options($providers)
                        ->default($providers->first())
                        ->required()
                        ->helperText(__('Select which invoice provider integration to use')),
                    Select::make('invoice_type')
                        ->label(__('Invoice Type'))
                        ->options([
                            'e-archive' => __('E-Archive Invoice (E-Arşiv Fatura)'),
                            'e-invoice' => __('E-Invoice (E-Fatura)'),
                        ])
                        ->default('e-archive')
                        ->required()
                        ->helperText(__('Type of invoice to generate')),
                    Toggle::make('send_to_customer')
                        ->label(__('Send to Customer'))
                        ->helperText(__('Automatically send the invoice to the customer via email/SMS'))
                        ->default(false),
                ];
            })
            ->visible(function (Order $record) {
                // Only visible if there are active invoice provider integrations
                $hasProvider = Integration::where('type', IntegrationType::INVOICE_PROVIDER)
                    ->where('is_active', true)
                    ->exists();

                if (! $hasProvider) {
                    return false;
                }

                // Check if order already has a non-cancelled invoice
                $hasInvoice = $record->invoices()
                    ->whereNotIn('status', ['cancelled', 'failed'])
                    ->exists();

                return ! $hasInvoice;
            })
            ->action(function (Order $record, array $data) {
                try {
                    $action = new GenerateInvoiceActionHandler;
                    $invoice = $action->execute($record, $data);

                    Notification::make()
                        ->success()
                        ->title(__('Invoice Generated'))
                        ->body(__('Successfully generated invoice :number for order :order', [
                            'number' => $invoice->invoice_number,
                            'order' => $record->order_number,
                        ]))
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Invoice Generation Failed'))
                        ->body(__('Failed to generate invoice: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }
}
