<?php

namespace App\Filament\Resources\Integration\Integrations\Schemas;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
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
                    ->options(IntegrationType::class)
                    ->reactive()
                    ->columnSpan(1),

                Select::make('provider')
                    ->label(__('Provider'))
                    ->required()
                    ->options(function (callable $get) {
                        $type = $get('type');
                        if (! $type) {
                            return [];
                        }

                        // Handle both string and enum values
                        $typeEnum = $type instanceof IntegrationType ? $type : IntegrationType::from($type);
                        $providers = IntegrationProvider::forType($typeEnum);

                        return collect($providers)->mapWithKeys(function (IntegrationProvider $provider) {
                            return [$provider->value => $provider->getLabel()];
                        })->toArray();
                    })
                    ->reactive()
                    ->columnSpan(1),

                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->default(true)
                    ->inline(false)
                    ->columnSpan(1),

                Select::make('location_id')
                    ->label(__('Warehouse / Location'))
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText(__('Select the warehouse/location for inventory management. Orders from this integration will deduct inventory from this location.'))
                    ->visible(fn (Get $get): bool => $get('type') === IntegrationType::SALES_CHANNEL->value || $get('type') === IntegrationType::SALES_CHANNEL)
                    ->columnSpanFull(),

                Section::make(__('Provider Configuration'))
                    ->description(fn (Get $get): string => self::getProviderDescription($get('type'), $get('provider')))
                    ->schema(fn (Get $get): array => self::getProviderFields($get('type'), $get('provider')))
                    ->visible(fn (Get $get): bool => filled($get('type')) && filled($get('provider')))
                    ->columnSpanFull(),
            ]);
    }

    protected static function getProviderDescription(IntegrationType|string|null $type, IntegrationProvider|string|null $provider): string
    {
        if (! $type || ! $provider) {
            return '';
        }

        $providerInfo = ProviderRegistry::getProvider($type, $provider);

        return $providerInfo['description'] ?? '';
    }

    protected static function getProviderFields(IntegrationType|string|null $type, IntegrationProvider|string|null $provider): array
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
