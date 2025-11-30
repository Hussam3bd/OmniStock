<?php

namespace App\Filament\Resources\Integration\Integrations\Pages;

use App\Filament\Resources\Integration\Integrations\IntegrationResource;
use App\Services\Integrations\ProviderRegistry;
use Filament\Resources\Pages\Page;

class Marketplace extends Page
{
    protected static string $resource = IntegrationResource::class;

    protected string $view = 'filament.resources.integration.integrations.pages.marketplace';

    protected static ?string $title = 'Integration Marketplace';

    public function getProviders(): array
    {
        return ProviderRegistry::getProviders();
    }
}
