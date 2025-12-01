<?php

namespace App\Services\Integrations\SalesChannels\Shopify\Webhooks;

use App\Models\Integration\Integration;
use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator as SignatureValidatorInterface;
use Spatie\WebhookClient\WebhookConfig;

class SignatureValidator implements SignatureValidatorInterface
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        // Get HMAC from webhook header (Shopify sends X-Shopify-Hmac-SHA256)
        $providedHmac = $request->header('X-Shopify-Hmac-SHA256');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');

        if (! $providedHmac) {
            activity()
                ->withProperties([
                    'error' => 'No HMAC provided in webhook request',
                    'headers' => $request->headers->all(),
                ])
                ->log('shopify_webhook_validation_failed');

            return false;
        }

        // Find the integration by shop domain
        $integration = Integration::where('type', 'sales_channel')
            ->where('provider', 'shopify')
            ->where('is_active', true)
            ->get()
            ->first(function ($integration) use ($shopDomain) {
                $configuredDomain = $integration->settings['shop_domain'] ?? null;

                // Remove .myshopify.com suffix for comparison
                $configuredDomain = str_replace('.myshopify.com', '', $configuredDomain);
                $requestDomain = str_replace('.myshopify.com', '', $shopDomain ?? '');

                return $configuredDomain === $requestDomain;
            });

        if (! $integration) {
            activity()
                ->withProperties([
                    'error' => 'No matching integration found for shop domain',
                    'shop_domain' => $shopDomain,
                ])
                ->log('shopify_webhook_validation_failed');

            return false;
        }

        // Verify HMAC signature
        $data = $request->getContent();
        $secret = $integration->settings['api_secret'] ?? $integration->settings['access_token'];

        if (! $secret) {
            activity()
                ->performedOn($integration)
                ->withProperties([
                    'error' => 'No secret configured for Shopify integration',
                ])
                ->log('shopify_webhook_validation_failed');

            return false;
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $secret, true));

        if (! hash_equals($calculatedHmac, $providedHmac)) {
            activity()
                ->performedOn($integration)
                ->withProperties([
                    'error' => 'HMAC signature mismatch',
                ])
                ->log('shopify_webhook_validation_failed');

            return false;
        }

        // Store integration ID in request for use in webhook processing
        $request->merge(['_shopify_integration_id' => $integration->id]);

        activity()
            ->performedOn($integration)
            ->withProperties([
                'webhook_validated' => true,
                'shop_domain' => $shopDomain,
            ])
            ->log('shopify_webhook_validated');

        return true;
    }
}
