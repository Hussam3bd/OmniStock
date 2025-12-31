<?php

namespace App\Services\Integrations;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Models\Integration\Integration;
use App\Services\Integrations\Contracts\InvoiceProviderAdapter;
use App\Services\Integrations\Contracts\PaymentGatewayAdapter;
use App\Services\Integrations\Contracts\SalesChannelAdapter;
use App\Services\Integrations\Contracts\ShippingProviderAdapter;
use App\Services\Integrations\InvoiceProviders\TrendyolEFatura\TrendyolEFaturaAdapter;
use App\Services\Integrations\PaymentGateways\IyzicoAdapter;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use App\Services\Integrations\ShippingProviders\BasitKargo\BasitKargoAdapter;

class AdapterFactory
{
    /**
     * Create an adapter instance for the given integration
     */
    public static function make(Integration $integration): SalesChannelAdapter|ShippingProviderAdapter|PaymentGatewayAdapter|InvoiceProviderAdapter
    {
        return match ($integration->type) {
            IntegrationType::SALES_CHANNEL => self::makeSalesChannelAdapter($integration),
            IntegrationType::SHIPPING_PROVIDER => self::makeShippingProviderAdapter($integration),
            IntegrationType::PAYMENT_GATEWAY => self::makePaymentGatewayAdapter($integration),
            IntegrationType::INVOICE_PROVIDER => self::makeInvoiceProviderAdapter($integration),
            default => throw new \InvalidArgumentException("Unsupported integration type: {$integration->type->value}"),
        };
    }

    /**
     * Create a sales channel adapter
     */
    protected static function makeSalesChannelAdapter(Integration $integration): SalesChannelAdapter
    {
        return match ($integration->provider) {
            IntegrationProvider::SHOPIFY => new ShopifyAdapter($integration),
            IntegrationProvider::TRENDYOL => new TrendyolAdapter($integration),
            default => throw new \InvalidArgumentException("Unsupported sales channel provider: {$integration->provider->value}"),
        };
    }

    /**
     * Create a shipping provider adapter
     */
    protected static function makeShippingProviderAdapter(Integration $integration): ShippingProviderAdapter
    {
        return match ($integration->provider) {
            IntegrationProvider::BASIT_KARGO => new BasitKargoAdapter($integration),
            default => throw new \InvalidArgumentException("Unsupported shipping provider: {$integration->provider->value}"),
        };
    }

    /**
     * Create a payment gateway adapter
     */
    protected static function makePaymentGatewayAdapter(Integration $integration): PaymentGatewayAdapter
    {
        return match ($integration->provider) {
            IntegrationProvider::IYZICO => new IyzicoAdapter($integration),
            default => throw new \InvalidArgumentException("Unsupported payment gateway: {$integration->provider->value}"),
        };
    }

    /**
     * Create an invoice provider adapter
     */
    protected static function makeInvoiceProviderAdapter(Integration $integration): InvoiceProviderAdapter
    {
        return match ($integration->provider) {
            IntegrationProvider::TRENDYOL_EFATURA => new TrendyolEFaturaAdapter($integration),
            default => throw new \InvalidArgumentException("Unsupported invoice provider: {$integration->provider->value}"),
        };
    }
}
