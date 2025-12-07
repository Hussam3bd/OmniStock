<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\Webhooks;

use Illuminate\Http\Request;
use Spatie\WebhookClient\WebhookProfile\WebhookProfile as BaseWebhookProfile;

class WebhookProfile implements BaseWebhookProfile
{
    public function shouldProcess(Request $request): bool
    {
        // Process all validated webhooks
        return true;
    }
}
