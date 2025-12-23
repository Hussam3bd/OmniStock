<?php

namespace App\Filament\Pages\Settings;

use App\Settings\SmsSettings as SmsSettingsClass;
use BackedEnum;
use Filament\Forms;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SmsSettings extends SettingsPage
{
    protected static string $settings = SmsSettingsClass::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('SMS Settings');
    }

    public function getTitle(): string
    {
        return __('SMS Settings');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('SMS Templates'))
                    ->description(__('Configure SMS message templates for customer notifications'))
                    ->schema([
                        Forms\Components\Textarea::make('awaiting_pickup_template')
                            ->label(__('Distribution Center Pickup Template'))
                            ->helperText(__('Available variables: {{first_name}}, {{last_name}}, {{full_name}}, {{order_number}}, {{shipping_carrier}}, {{tracking_number}}, {{distribution_center_name}}, {{distribution_center_location}}, {{city}}, {{district}}, {{address}}'))
                            ->rows(8)
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
