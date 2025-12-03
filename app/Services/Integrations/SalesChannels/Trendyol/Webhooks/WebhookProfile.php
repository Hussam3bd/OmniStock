<?php

namespace App\Services\Integrations\SalesChannels\Trendyol\Webhooks;

use Illuminate\Http\Request;
use Spatie\WebhookClient\WebhookProfile\WebhookProfile as BaseWebhookProfile;

class WebhookProfile implements BaseWebhookProfile
{
    public function shouldProcess(Request $request): bool
    {
        return true;
    }
}
