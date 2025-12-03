<?php

namespace App\Services\Integrations;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;

class ProviderRegistry
{
    public static function getProviders(): array
    {
        return [
            IntegrationType::SALES_CHANNEL->value => [
                IntegrationProvider::SHOPIFY->value => [
                    'name' => 'Shopify',
                    'description' => 'Connect your Shopify store to sync orders, products, inventory, customers, and addresses. Purchase orders will be supported in a future update.',
                    'icon' => 'heroicon-o-shopping-bag',
                    'color' => 'success',
                    'required_fields' => [
                        'shop_domain' => [
                            'label' => 'Shop Domain',
                            'type' => 'text',
                            'placeholder' => 'your-store.myshopify.com',
                            'required' => true,
                            'helper' => 'Your Shopify store domain (e.g., your-store.myshopify.com)',
                        ],
                        'access_token' => [
                            'label' => 'Admin API Access Token',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Create a custom app in Shopify Admin: Apps > App development > Create an app. Required scopes: read_products, write_products, read_orders, write_orders, read_inventory, write_inventory, read_customers, write_customers, read_locations',
                        ],
                        'api_secret' => [
                            'label' => 'API Secret Key',
                            'type' => 'password',
                            'required' => false,
                            'helper' => 'Your custom app\'s API secret key (required for webhook HMAC verification)',
                        ],
                        'location_id' => [
                            'label' => 'Default Location',
                            'type' => 'relationship',
                            'relationship_name' => 'location',
                            'relationship_title_attribute' => 'name',
                            'required' => false,
                            'helper' => 'Select the inventory location for this Shopify integration',
                        ],
                        'api_version' => [
                            'label' => 'API Version',
                            'type' => 'select',
                            'options' => [
                                '2025-10' => '2025-10 (Latest)',
                                '2025-07' => '2025-07',
                                '2025-04' => '2025-04',
                                '2025-01' => '2025-01',
                                '2024-10' => '2024-10',
                                '2024-07' => '2024-07',
                            ],
                            'default' => '2025-10',
                            'required' => false,
                            'helper' => 'Shopify API version (we recommend using the latest stable version)',
                            'searchable' => false,
                        ],
                    ],
                    'documentation_url' => 'https://shopify.dev/docs/api/admin-rest',
                ],
                IntegrationProvider::TRENDYOL->value => [
                    'name' => 'Trendyol',
                    'description' => 'Connect your Trendyol seller account to manage Turkish marketplace orders',
                    'icon' => 'heroicon-o-building-storefront',
                    'color' => 'warning',
                    'required_fields' => [
                        'api_key' => [
                            'label' => 'API Key',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Trendyol API Key from seller panel',
                        ],
                        'api_secret' => [
                            'label' => 'API Secret',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Trendyol API Secret from seller panel',
                        ],
                        'supplier_id' => [
                            'label' => 'Supplier ID',
                            'type' => 'text',
                            'required' => true,
                            'helper' => 'Your Trendyol supplier ID',
                        ],
                    ],
                    'documentation_url' => 'https://developers.trendyol.com/',
                ],
            ],
            IntegrationType::SHIPPING_PROVIDER->value => [
                IntegrationProvider::BASIT_KARGO->value => [
                    'name' => 'Basit Kargo',
                    'description' => 'Turkish shipping aggregator - compare rates and ship with multiple carriers',
                    'icon' => 'heroicon-o-truck',
                    'color' => 'info',
                    'required_fields' => [
                        'api_token' => [
                            'label' => 'API Token',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Your Basit Kargo API token',
                        ],
                        'vat_included' => [
                            'label' => 'Prices Include VAT',
                            'type' => 'toggle',
                            'required' => false,
                            'default' => true,
                            'helper' => 'Whether the prices returned by Basit Kargo API include VAT (default: Yes)',
                        ],
                        'test_mode' => [
                            'label' => 'Test Mode',
                            'type' => 'toggle',
                            'required' => false,
                            'default' => true,
                            'helper' => 'Use test environment for development',
                        ],
                    ],
                    'documentation_url' => 'https://basitkargo.com/api',
                ],
            ],
            IntegrationType::PAYMENT_GATEWAY->value => [
                IntegrationProvider::STRIPE->value => [
                    'name' => 'Stripe',
                    'description' => 'Accept payments globally with Stripe',
                    'icon' => 'heroicon-o-credit-card',
                    'color' => 'primary',
                    'required_fields' => [
                        'publishable_key' => [
                            'label' => 'Publishable Key',
                            'type' => 'text',
                            'required' => true,
                            'helper' => 'Stripe publishable key (pk_live_...)',
                        ],
                        'secret_key' => [
                            'label' => 'Secret Key',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Stripe secret key (sk_live_...)',
                        ],
                        'webhook_secret' => [
                            'label' => 'Webhook Secret',
                            'type' => 'password',
                            'required' => false,
                            'helper' => 'Webhook signing secret for verifying events',
                        ],
                    ],
                    'documentation_url' => 'https://stripe.com/docs/api',
                ],
                IntegrationProvider::IYZICO->value => [
                    'name' => 'Iyzico',
                    'description' => 'Turkish payment gateway for local payment methods',
                    'icon' => 'heroicon-o-banknotes',
                    'color' => 'warning',
                    'required_fields' => [
                        'api_key' => [
                            'label' => 'API Key',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Iyzico API key',
                        ],
                        'secret_key' => [
                            'label' => 'Secret Key',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Iyzico secret key',
                        ],
                        'base_url' => [
                            'label' => 'Base URL',
                            'type' => 'text',
                            'required' => true,
                            'default' => 'https://api.iyzipay.com',
                            'helper' => 'API endpoint URL',
                        ],
                    ],
                    'documentation_url' => 'https://dev.iyzipay.com/',
                ],
            ],
            IntegrationType::INVOICE_PROVIDER->value => [
                IntegrationProvider::TRENDYOL_EFATURA->value => [
                    'name' => 'Trendyol E-Fatura',
                    'description' => 'Generate legal Turkish e-invoices compliant with tax regulations',
                    'icon' => 'heroicon-o-document-text',
                    'color' => 'success',
                    'required_fields' => [
                        'username' => [
                            'label' => 'Username',
                            'type' => 'text',
                            'required' => true,
                            'helper' => 'Your Trendyol E-Fatura username',
                        ],
                        'password' => [
                            'label' => 'Password',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Your Trendyol E-Fatura password',
                        ],
                        'company_tax_id' => [
                            'label' => 'Tax ID',
                            'type' => 'text',
                            'required' => true,
                            'helper' => 'Company tax identification number',
                        ],
                        'test_mode' => [
                            'label' => 'Test Mode',
                            'type' => 'toggle',
                            'required' => false,
                            'default' => true,
                            'helper' => 'Use test environment',
                        ],
                    ],
                    'documentation_url' => 'https://trendyolefaturam.com/',
                ],
            ],
        ];
    }

    public static function getProvider(IntegrationType|string $type, IntegrationProvider|string $provider): ?array
    {
        $typeValue = $type instanceof IntegrationType ? $type->value : $type;
        $providerValue = $provider instanceof IntegrationProvider ? $provider->value : $provider;

        return self::getProviders()[$typeValue][$providerValue] ?? null;
    }

    public static function getProvidersByType(IntegrationType|string $type): array
    {
        $typeValue = $type instanceof IntegrationType ? $type->value : $type;

        return self::getProviders()[$typeValue] ?? [];
    }
}
