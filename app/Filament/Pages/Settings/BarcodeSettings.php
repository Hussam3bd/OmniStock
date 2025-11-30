<?php

namespace App\Filament\Pages\Settings;

use App\Settings\BarcodeSettings as BarcodeSettingsClass;
use BackedEnum;
use Filament\Forms;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BarcodeSettings extends SettingsPage
{
    protected static string $settings = BarcodeSettingsClass::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Barcode Settings');
    }

    public function getTitle(): string
    {
        return __('Barcode Settings');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Barcode Configuration'))
                    ->description(__('Configure UPC-A barcode generation settings'))
                    ->schema([
                        Forms\Components\Select::make('barcode_format')
                            ->label(__('Barcode Format'))
                            ->options([
                                'upca' => 'UPC-A',
                                'ean13' => 'EAN-13',
                                'ean8' => 'EAN-8',
                            ])
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('barcode_country_code')
                            ->label(__('Country Code'))
                            ->helperText(__('3-digit country code for UPC-A (e.g., 869 for Turkey)'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(999)
                            ->required(),

                        Forms\Components\TextInput::make('barcode_company_prefix')
                            ->label(__('GS1 Company Prefix'))
                            ->helperText(__('Your GS1 company prefix (leave empty to use auto-generated)'))
                            ->numeric()
                            ->maxLength(9),

                        Forms\Components\Toggle::make('barcode_auto_generate')
                            ->label(__('Auto-generate Barcodes'))
                            ->helperText(__('Automatically generate barcodes when creating variants'))
                            ->default(false),
                    ])
                    ->columns(2),
            ]);
    }
}
