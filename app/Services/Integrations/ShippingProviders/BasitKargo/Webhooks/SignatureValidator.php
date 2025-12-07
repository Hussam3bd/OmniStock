<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\Webhooks;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Models\Integration\Integration;
use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator as SignatureValidatorInterface;
use Spatie\WebhookClient\WebhookConfig;

class SignatureValidator implements SignatureValidatorInterface
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        // BasitKargo sends webhooks with Authorization header containing the API token
        // Format: Authorization: Bearer {api_token}
        $providedToken = $request->bearerToken()
            ?? $request->header('X-Api-Token')
            ?? $request->header('Authorization');

        if (! $providedToken) {
            activity()
                ->withProperties([
                    'error' => 'No API token provided in webhook request',
                    'headers' => $request->headers->all(),
                ])
                ->log('basitkargo_webhook_validation_failed');

            return false;
        }

        // Remove "Bearer " prefix if present
        $providedToken = str_replace('Bearer ', '', $providedToken);

        // Find the integration by matching the provided API token
        // This allows multiple BasitKargo integrations
        $integration = Integration::where('type', IntegrationType::SHIPPING_PROVIDER)
            ->where('provider', IntegrationProvider::BASIT_KARGO)
            ->where('is_active', true)
            ->get()
            ->first(function ($integration) use ($providedToken) {
                return ($integration->settings['api_token'] ?? null) === $providedToken;
            });

        if (! $integration) {
            activity()
                ->withProperties([
                    'error' => 'No matching integration found for provided API token',
                    'provided_token_prefix' => substr($providedToken, 0, 8).'...',
                ])
                ->log('basitkargo_webhook_validation_failed');

            return false;
        }

        // Store integration ID in request for use in webhook processing
        $request->merge(['_basitkargo_integration_id' => $integration->id]);

        activity()
            ->performedOn($integration)
            ->withProperties([
                'webhook_validated' => true,
            ])
            ->log('basitkargo_webhook_validated');

        return true;
    }
}
