<?php

namespace App\Services\Integrations\SalesChannels\Trendyol\Webhooks;

use App\Models\Integration\Integration;
use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

class SignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $integration = Integration::where('type', 'sales_channel')
            ->where('provider', 'trendyol')
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            return false;
        }

        $apiKey = $integration->settings['api_key'] ?? null;

        if (! $apiKey) {
            return false;
        }

        $providedApiKey = $request->header('X-Trendyol-Api-Key')
            ?? $request->input('apiKey')
            ?? $request->bearerToken();

        return $providedApiKey === $apiKey;
    }
}
