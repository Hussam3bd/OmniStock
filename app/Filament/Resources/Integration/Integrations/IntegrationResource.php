<?php

namespace App\Filament\Resources\Integration\Integrations;

use App\Filament\Resources\Integration\Integrations\Pages\CreateIntegration;
use App\Filament\Resources\Integration\Integrations\Pages\EditIntegration;
use App\Filament\Resources\Integration\Integrations\Pages\ListIntegrations;
use App\Filament\Resources\Integration\Integrations\Pages\Marketplace;
use App\Filament\Resources\Integration\Integrations\Schemas\IntegrationForm;
use App\Filament\Resources\Integration\Integrations\Tables\IntegrationsTable;
use App\Models\Integration\Integration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IntegrationResource extends Resource
{
    protected static ?string $model = Integration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function form(Schema $schema): Schema
    {
        return IntegrationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IntegrationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntegrations::route('/'),
            'marketplace' => Marketplace::route('/marketplace'),
            'create' => CreateIntegration::route('/create'),
            'edit' => EditIntegration::route('/{record}/edit'),
        ];
    }
}
