<?php

namespace App\Services\Integrations\SalesChannels\Trendyol\Webhooks;

use App\Models\Integration\Integration;
use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator as SignatureValidatorInterface;
use Spatie\WebhookClient\WebhookConfig;

class SignatureValidator implements SignatureValidatorInterface
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        // Get API key from webhook header (Trendyol sends X-Api-Key)
        $providedApiKey = $request->header('X-Api-Key')
            ?? $request->header('X-Trendyol-Api-Key') // Fallback for compatibility
            ?? $request->input('apiKey')
            ?? $request->bearerToken();

        if (! $providedApiKey) {
            activity()
                ->withProperties([
                    'error' => 'No API key provided in webhook request',
                    'headers' => $request->headers->all(),
                ])
                ->log('trendyol_webhook_validation_failed');

            return false;
        }

        // Find the integration by matching the provided API key
        // This allows multiple Trendyol integrations
        $integration = Integration::where('type', 'sales_channel')
            ->where('provider', 'trendyol')
            ->where('is_active', true)
            ->get()
            ->first(function ($integration) use ($providedApiKey) {
                return ($integration->settings['api_key'] ?? null) === $providedApiKey;
            });

        if (! $integration) {
            activity()
                ->withProperties([
                    'error' => 'No matching integration found for provided API key',
                    'provided_key_prefix' => substr($providedApiKey, 0, 8).'...',
                ])
                ->log('trendyol_webhook_validation_failed');

            return false;
        }

        // Store integration ID in request for use in webhook processing
        $request->merge(['_trendyol_integration_id' => $integration->id]);

        activity()
            ->performedOn($integration)
            ->withProperties([
                'webhook_validated' => true,
            ])
            ->log('trendyol_webhook_validated');

        return true;
    }
}
