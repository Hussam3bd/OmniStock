<?php

namespace App\Services\Integrations;

class ProviderRegistry
{
    public static function getProviders(): array
    {
        return [
            'sales_channel' => [
                'shopify' => [
                    'name' => 'Shopify',
                    'description' => 'Connect your Shopify store to sync orders, inventory, and customers',
                    'icon' => 'heroicon-o-shopping-bag',
                    'color' => 'success',
                    'required_fields' => [
                        'shop_domain' => [
                            'label' => 'Shop Domain',
                            'type' => 'text',
                            'placeholder' => 'your-store.myshopify.com',
                            'required' => true,
                            'helper' => 'Your Shopify store domain',
                        ],
                        'api_key' => [
                            'label' => 'API Key',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Admin API access token',
                        ],
                        'api_secret' => [
                            'label' => 'API Secret',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Admin API secret key',
                        ],
                    ],
                    'documentation_url' => 'https://shopify.dev/docs/api/admin-rest',
                ],
                'trendyol' => [
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
            'shipping_provider' => [
                'basit_kargo' => [
                    'name' => 'Basit Kargo',
                    'description' => 'Turkish shipping aggregator - compare rates and ship with multiple carriers',
                    'icon' => 'heroicon-o-truck',
                    'color' => 'info',
                    'required_fields' => [
                        'api_key' => [
                            'label' => 'API Key',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Your Basit Kargo API key',
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
            'payment_gateway' => [
                'stripe' => [
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
                'iyzico' => [
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
            'invoice_provider' => [
                'trendyol_efatura' => [
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

    public static function getProvider(string $type, string $provider): ?array
    {
        return self::getProviders()[$type][$provider] ?? null;
    }

    public static function getProvidersByType(string $type): array
    {
        return self::getProviders()[$type] ?? [];
    }
}
