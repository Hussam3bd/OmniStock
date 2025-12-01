<?php

namespace App\Filament\Resources\Integration\Integrations\Schemas;

use App\Services\Integrations\ProviderRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class IntegrationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Integration Name'))
                    ->required()
                    ->maxLength(255)
                    ->helperText(__('e.g., "Main Shopify Store", "Backup Trendyol"'))
                    ->columnSpanFull(),

                Select::make('type')
                    ->label(__('Integration Type'))
                    ->required()
                    ->options([
                        'sales_channel' => __('Sales Channel'),
                        'shipping_provider' => __('Shipping Provider'),
                        'payment_gateway' => __('Payment Gateway'),
                        'invoice_provider' => __('Invoice Provider'),
                    ])
                    ->reactive()
                    ->columnSpan(1),

                Select::make('provider')
                    ->label(__('Provider'))
                    ->required()
                    ->options(function (callable $get) {
                        return match ($get('type')) {
                            'sales_channel' => [
                                'shopify' => 'Shopify',
                                'trendyol' => 'Trendyol',
                            ],
                            'shipping_provider' => [
                                'basit_kargo' => 'Basit Kargo',
                            ],
                            'payment_gateway' => [
                                'stripe' => 'Stripe',
                                'iyzico' => 'Iyzico',
                            ],
                            'invoice_provider' => [
                                'trendyol_efatura' => 'Trendyol E-Fatura',
                            ],
                            default => [],
                        };
                    })
                    ->reactive()
                    ->columnSpan(1),

                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->default(true)
                    ->inline(false)
                    ->columnSpan(1),

                Section::make(__('Provider Configuration'))
                    ->description(fn (Get $get): string => self::getProviderDescription($get('type'), $get('provider')))
                    ->schema(fn (Get $get): array => self::getProviderFields($get('type'), $get('provider')))
                    ->visible(fn (Get $get): bool => filled($get('type')) && filled($get('provider')))
                    ->columnSpanFull(),
            ]);
    }

    protected static function getProviderDescription(string $type, string $provider): string
    {
        $providerInfo = ProviderRegistry::getProvider($type, $provider);

        return $providerInfo['description'] ?? '';
    }

    protected static function getProviderFields(?string $type, ?string $provider): array
    {
        if (! $type || ! $provider) {
            return [];
        }

        $providerInfo = ProviderRegistry::getProvider($type, $provider);

        if (! $providerInfo || ! isset($providerInfo['required_fields'])) {
            return [];
        }

        $fields = [];

        foreach ($providerInfo['required_fields'] as $fieldKey => $fieldConfig) {
            $field = match ($fieldConfig['type']) {
                'toggle' => Toggle::make("settings.{$fieldKey}")
                    ->label($fieldConfig['label'])
                    ->default($fieldConfig['default'] ?? false)
                    ->required($fieldConfig['required'] ?? false)
                    ->helperText($fieldConfig['helper'] ?? null),

                'password' => TextInput::make("settings.{$fieldKey}")
                    ->label($fieldConfig['label'])
                    ->password()
                    ->revealable()
                    ->required($fieldConfig['required'] ?? false)
                    ->placeholder($fieldConfig['placeholder'] ?? null)
                    ->helperText($fieldConfig['helper'] ?? null),

                'select' => Select::make("settings.{$fieldKey}")
                    ->label($fieldConfig['label'])
                    ->options($fieldConfig['options'] ?? [])
                    ->required($fieldConfig['required'] ?? false)
                    ->placeholder($fieldConfig['placeholder'] ?? null)
                    ->default($fieldConfig['default'] ?? null)
                    ->helperText($fieldConfig['helper'] ?? null)
                    ->searchable($fieldConfig['searchable'] ?? false),

                'relationship' => Select::make("settings.{$fieldKey}")
                    ->label($fieldConfig['label'])
                    ->relationship(
                        name: $fieldConfig['relationship_name'],
                        titleAttribute: $fieldConfig['relationship_title_attribute'] ?? 'name',
                        modifyQueryUsing: fn ($query) => $query->where('is_active', true)
                    )
                    ->required($fieldConfig['required'] ?? false)
                    ->placeholder($fieldConfig['placeholder'] ?? null)
                    ->helperText($fieldConfig['helper'] ?? null)
                    ->searchable()
                    ->preload(),

                default => TextInput::make("settings.{$fieldKey}")
                    ->label($fieldConfig['label'])
                    ->required($fieldConfig['required'] ?? false)
                    ->placeholder($fieldConfig['placeholder'] ?? null)
                    ->default($fieldConfig['default'] ?? null)
                    ->helperText($fieldConfig['helper'] ?? null),
            };

            $fields[] = $field;
        }

        return $fields;
    }
}
