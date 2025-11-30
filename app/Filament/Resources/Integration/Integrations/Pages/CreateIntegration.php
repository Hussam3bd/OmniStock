<?php

namespace App\Filament\Resources\Integration\Integrations\Pages;

use App\Filament\Resources\Integration\Integrations\IntegrationResource;
use App\Services\Integrations\ProviderRegistry;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateIntegration extends CreateRecord
{
    protected static string $resource = IntegrationResource::class;

    public function mount(): void
    {
        parent::mount();

        $type = request()->query('type');
        $provider = request()->query('provider');

        if ($type && $provider) {
            $providerInfo = ProviderRegistry::getProvider($type, $provider);

            if ($providerInfo) {
                $this->form->fill([
                    'type' => $type,
                    'provider' => $provider,
                    'name' => $providerInfo['name'],
                ]);
            }
        }
    }

    protected function getRedirectUrl(): string
    {
        return IntegrationResource::getUrl('index');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation($data);

        $this->notifySuccess(__('Integration installed successfully!'));

        return $record;
    }

    protected function notifySuccess(string $message): void
    {
        \Filament\Notifications\Notification::make()
            ->title($message)
            ->success()
            ->send();
    }
}
