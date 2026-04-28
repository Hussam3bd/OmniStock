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
                        'shopify_location_id' => [
                            'label' => 'Shopify Location ID',
                            'type' => 'text',
                            'required' => false,
                            'placeholder' => '83437650147',
                            'helper' => 'The Shopify-side location ID where stock is synced. Find it at /admin/api/{version}/locations.json or in the Shopify admin location URL.',
                        ],
                        'auto_sync_stock' => [
                            'label' => 'Auto-Sync Stock to Shopify',
                            'type' => 'toggle',
                            'required' => false,
                            'default' => false,
                            'helper' => 'Automatically push stock changes (sales, returns, cancellations, purchase receipts) to Shopify. Prices are never changed.',
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
                        'auto_sync_stock' => [
                            'label' => 'Auto-Sync Stock to Trendyol',
                            'type' => 'toggle',
                            'required' => false,
                            'default' => false,
                            'helper' => 'Automatically push stock changes (sales, returns, cancellations, purchase receipts) to Trendyol. Prices are never changed.',
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
                        '_section_invoice' => [
                            'type' => 'section',
                            'label' => 'Invoice Information',
                            'description' => 'Carrier details for invoice generation (required for Turkish e-invoices)',
                        ],
                        'carrier_name' => [
                            'label' => 'Carrier Name',
                            'type' => 'text',
                            'required' => false,
                            'placeholder' => 'e.g., BasitKargo',
                            'helper' => 'The official name of your shipping carrier (used for invoice delivery information)',
                        ],
                        'carrier_tax_id' => [
                            'label' => 'Carrier Tax ID (Vergi Kimlik Numarası)',
                            'type' => 'text',
                            'required' => false,
                            'placeholder' => '1234567890',
                            'helper' => 'The 10-digit tax ID of your shipping carrier (required for Turkish e-invoices)',
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
                            'helper' => 'Iyzico API key from merchant panel',
                        ],
                        'secret_key' => [
                            'label' => 'Secret Key',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Iyzico secret key from merchant panel',
                        ],
                        'test_mode' => [
                            'label' => 'Test Mode',
                            'type' => 'toggle',
                            'required' => false,
                            'default' => false,
                            'helper' => 'Use sandbox environment for testing (sandbox-api.iyzipay.com)',
                        ],
                    ],
                    'documentation_url' => 'https://dev.iyzipay.com/',
                ],
            ],
            IntegrationType::INVOICE_PROVIDER->value => [
                IntegrationProvider::TRENDYOL_EFATURA->value => [
                    'name' => 'Trendyol E-Fatura',
                    'description' => 'Generate legal Turkish e-invoices and e-archive invoices compliant with tax regulations. Company information will be automatically retrieved from your Trendyol E-Fatura account after authentication.',
                    'icon' => 'heroicon-o-document-text',
                    'color' => 'success',
                    'required_fields' => [
                        '_section_auth' => [
                            'type' => 'section',
                            'label' => 'Authentication',
                            'description' => 'Your Trendyol E-Fatura account credentials',
                        ],
                        'email' => [
                            'label' => 'Email',
                            'type' => 'text',
                            'required' => true,
                            'placeholder' => 'your@email.com',
                            'helper' => 'Your Trendyol E-Fatura account email',
                        ],
                        'password' => [
                            'label' => 'Password',
                            'type' => 'password',
                            'required' => true,
                            'helper' => 'Your Trendyol E-Fatura account password',
                        ],
                        'test_mode' => [
                            'label' => 'Test Mode',
                            'type' => 'toggle',
                            'required' => false,
                            'default' => true,
                            'helper' => 'Use test environment (stage-apigateway.trendyolefaturam.com). Uncheck for production (apigateway.trendyolecozum.com)',
                        ],
                        '_section_account' => [
                            'type' => 'section',
                            'label' => 'Account Information',
                            'description' => 'These fields will be automatically filled after your first successful authentication',
                        ],
                        'user_id' => [
                            'label' => 'User ID',
                            'type' => 'number',
                            'required' => false,
                            'helper' => 'Automatically filled from authentication response',
                            'disabled' => true,
                        ],
                        'company_id' => [
                            'label' => 'Company ID',
                            'type' => 'number',
                            'required' => false,
                            'helper' => 'Automatically filled from authentication response (if not filled, it can be entered manually)',
                        ],
                        'company_tax_id' => [
                            'label' => 'Company Tax ID (Vergi Kimlik Numarası)',
                            'type' => 'text',
                            'required' => true,
                            'placeholder' => '1234567890',
                            'helper' => 'Your company\'s 10-digit tax identification number for invoice generation',
                        ],
                        '_section_invoice' => [
                            'type' => 'section',
                            'label' => 'Invoice Settings',
                            'description' => 'Configure how your invoices will be generated',
                        ],
                        'prefix' => [
                            'label' => 'Invoice Prefix',
                            'type' => 'text',
                            'required' => false,
                            'placeholder' => 'ABC',
                            'helper' => 'Optional 3-character invoice prefix (uppercase letters or numbers only, e.g., DAP, INV, ABC). Must be registered in your Trendyol E-Fatura account. Leave empty to use your default prefix.',
                            'maxLength' => 3,
                            'regex' => '/^[A-Z0-9]{3}$/',
                            'rules' => ['nullable', 'regex:/^[A-Z0-9]{3}$/'],
                            'uppercase' => true,
                        ],
                        'bank_accounts' => [
                            'label' => 'Bank Accounts',
                            'type' => 'repeater',
                            'required' => false,
                            'helper' => 'Add your bank account information to display on invoices for customer payments',
                            'schema' => [
                                'bank_name' => [
                                    'label' => 'Bank Name',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g., Garanti Bankası',
                                ],
                                'branch_name' => [
                                    'label' => 'Branch Name',
                                    'type' => 'text',
                                    'required' => false,
                                    'placeholder' => 'e.g., Çemberlitaş',
                                ],
                                'currency' => [
                                    'label' => 'Account Currency',
                                    'type' => 'select',
                                    'options' => [
                                        'TRY' => 'Turkish Lira (TRY)',
                                        'USD' => 'US Dollar (USD)',
                                        'EUR' => 'Euro (EUR)',
                                        'GBP' => 'British Pound (GBP)',
                                    ],
                                    'default' => 'TRY',
                                    'required' => true,
                                ],
                                'account_number' => [
                                    'label' => 'Account Number (IBAN)',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'TR780006200004400009050749',
                                ],
                            ],
                        ],
                    ],
                    'documentation_url' => 'https://developers.trendyolefaturam.com/',
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
