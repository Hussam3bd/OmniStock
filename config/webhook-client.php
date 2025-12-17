<?php

return [
    'configs' => [
        [
            'name' => 'trendyol',
            'signing_secret' => '',
            'signature_header_name' => 'X-Api-Key',
            'signature_validator' => \App\Services\Integrations\SalesChannels\Trendyol\Webhooks\SignatureValidator::class,
            'webhook_profile' => \App\Services\Integrations\SalesChannels\Trendyol\Webhooks\WebhookProfile::class,
            'webhook_response' => \Spatie\WebhookClient\WebhookResponse\DefaultRespondsTo::class,
            'webhook_model' => \Spatie\WebhookClient\Models\WebhookCall::class,
            'store_headers' => ['*'],
            'process_webhook_job' => \App\Jobs\ProcessTrendyolWebhook::class,
        ],
        [
            'name' => 'shopify',
            'signing_secret' => '',
            'signature_header_name' => 'X-Shopify-Hmac-SHA256',
            'signature_validator' => \App\Services\Integrations\SalesChannels\Shopify\Webhooks\SignatureValidator::class,
            'webhook_profile' => \App\Services\Integrations\SalesChannels\Shopify\Webhooks\WebhookProfile::class,
            'webhook_response' => \Spatie\WebhookClient\WebhookResponse\DefaultRespondsTo::class,
            'webhook_model' => \Spatie\WebhookClient\Models\WebhookCall::class,
            'store_headers' => ['*'],
            'process_webhook_job' => \App\Jobs\ProcessShopifyWebhook::class,
        ],
        [
            'name' => 'basitkargo',
            'signing_secret' => '',
            'signature_header_name' => 'Authorization',
            'signature_validator' => \App\Services\Integrations\ShippingProviders\BasitKargo\Webhooks\SignatureValidator::class,
            'webhook_profile' => \App\Services\Integrations\ShippingProviders\BasitKargo\Webhooks\WebhookProfile::class,
            'webhook_response' => \Spatie\WebhookClient\WebhookResponse\DefaultRespondsTo::class,
            'webhook_model' => \Spatie\WebhookClient\Models\WebhookCall::class,
            'store_headers' => ['*'],
            'process_webhook_job' => \App\Jobs\ProcessBasitKargoWebhook::class,
        ],
    ],

    /*
     * The integer amount of days after which models should be deleted.
     *
     * It deletes all records after 30 days. Set to null if no models should be deleted.
     */
    'delete_after_days' => 30,

    /*
     * Should a unique token be added to the route name
     */
    'add_unique_token_to_route_name' => false,
];
