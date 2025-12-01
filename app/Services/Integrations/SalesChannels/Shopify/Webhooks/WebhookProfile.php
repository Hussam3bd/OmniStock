<?php

namespace App\Services\Integrations\SalesChannels\Shopify\Webhooks;

use Illuminate\Http\Request;
use Spatie\WebhookClient\WebhookProfile\WebhookProfile as WebhookProfileInterface;

class WebhookProfile implements WebhookProfileInterface
{
    public function shouldProcess(Request $request): bool
    {
        // Process all Shopify webhooks
        return true;
    }
}
