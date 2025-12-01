<?php

namespace App\Services\Integrations\Webhooks;

use Illuminate\Http\Request;
use Spatie\WebhookClient\WebhookProfile\WebhookProfile;

class TrendyolWebhookProfile implements WebhookProfile
{
    public function shouldProcess(Request $request): bool
    {
        return true;
    }
}
