<?php

namespace App\Filament\Resources\Order\Orders\RelationManagers;

use App\Enums\Address\AddressType;
use App\Models\Address\District;
use App\Models\Address\Neighborhood;
use App\Models\Address\Province;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;

class AddressesRelationManager extends \Filament\Resources\RelationManagers\RelationManager
{
    protected static string $relationship = 'shippingAddress';

    protected static ?string $title = 'Addresses';

    protected static ?string $recordTitleAttribute = 'full_name';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)
                    ->schema([
                        Select::make('type')
                            ->options(AddressType::class)
                            ->required()
                            ->default(AddressType::RESIDENTIAL)
                            ->live(),

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., Home, Work, Office'),

                        TextInput::make('first_name')
                            ->required()
                            ->maxLength(255)
                            ->visible(fn (Get $get) => $get('type') === AddressType::RESIDENTIAL->value),

                        TextInput::make('last_name')
                            ->required()
                            ->maxLength(255)
                            ->visible(fn (Get $get) => $get('type') === AddressType::RESIDENTIAL->value),

                        TextInput::make('company_name')
                            ->required()
                            ->maxLength(255)
                            ->visible(fn (Get $get) => $get('type') === AddressType::INSTITUTIONAL->value),

                        PhoneInput::make('phone')
                            ->defaultCountry('TR')
                            ->countryOrder(['TR', 'US', 'GB'])
                            ->initialCountry('TR')
                            ->validateFor(),

                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),

                        Select::make('country_id')
                            ->label('Country')
                            ->relationship('country', 'name')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->getTranslation('name',
                                app()->getLocale()))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('province_id', null)),

                        Select::make('province_id')
                            ->label('Province')
                            ->options(function (Get $get) {
                                $countryId = $get('country_id');
                                if (! $countryId) {
                                    return [];
                                }

                                return Province::where('country_id', $countryId)
                                    ->get()
                                    ->pluck('name', 'id')
                                    ->map(fn ($name
                                    ) => is_array($name) ? ($name[app()->getLocale()] ?? $name['en'] ?? '') : $name);
                            })
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('district_id', null)),

                        Select::make('district_id')
                            ->label('District')
                            ->options(function (Get $get) {
                                $provinceId = $get('province_id');
                                if (! $provinceId) {
                                    return [];
                                }

                                return District::where('province_id', $provinceId)
                                    ->get()
                                    ->pluck('name', 'id')
                                    ->map(fn ($name
                                    ) => is_array($name) ? ($name[app()->getLocale()] ?? $name['en'] ?? '') : $name);
                            })
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('neighborhood_id', null)),

                        Select::make('neighborhood_id')
                            ->label('Neighborhood')
                            ->options(function (Get $get) {
                                $districtId = $get('district_id');
                                if (! $districtId) {
                                    return [];
                                }

                                return Neighborhood::where('district_id', $districtId)
                                    ->get()
                                    ->pluck('name', 'id')
                                    ->map(fn ($name
                                    ) => is_array($name) ? ($name[app()->getLocale()] ?? $name['en'] ?? '') : $name);
                            })
                            ->searchable()
                            ->preload(),
                    ]),

                Grid::make(2)
                    ->schema([
                        TextInput::make('address_line1')
                            ->label('Address Line 1')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('address_line2')
                            ->label('Address Line 2')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('building_name')
                            ->maxLength(255),

                        TextInput::make('building_number')
                            ->maxLength(50),

                        TextInput::make('floor')
                            ->maxLength(50),

                        TextInput::make('apartment')
                            ->maxLength(50),

                        TextInput::make('postal_code')
                            ->maxLength(20),
                    ]),

                Grid::make(2)
                    ->schema([
                        TextInput::make('tax_office')
                            ->maxLength(255)
                            ->visible(fn (Get $get) => $get('type') === AddressType::INSTITUTIONAL->value),

                        TextInput::make('tax_number')
                            ->maxLength(255)
                            ->visible(fn (Get $get) => $get('type') === AddressType::INSTITUTIONAL->value),

                        TextInput::make('identity_number')
                            ->maxLength(255),
                    ]),

                Textarea::make('delivery_instructions')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $order = $this->getOwnerRecord();
                $addressIds = array_filter([
                    $order->shipping_address_id,
                    $order->billing_address_id,
                ]);

                return $query->whereIn('id', $addressIds);
            })
            ->columns([
                TextColumn::make('id')
                    ->label('#'),

                TextColumn::make('address_type')
                    ->label(__('Type'))
                    ->badge()
                    ->getStateUsing(function ($record) {
                        $order = $this->getOwnerRecord();
                        $types = [];

                        if ($record->id === $order->shipping_address_id) {
                            $types[] = 'Shipping';
                        }

                        if ($record->id === $order->billing_address_id) {
                            $types[] = 'Billing';
                        }

                        return implode(' & ', $types);
                    })
                    ->color(fn ($state) => str_contains($state, 'Shipping') && str_contains($state,
                        'Billing') ? 'success' : 'primary'),

                TextColumn::make('type')
                    ->label(__('Address Type'))
                    ->badge(),

                TextColumn::make('full_name')
                    ->label(__('Name'))
                    ->searchable(['first_name', 'last_name', 'company_name']),

                TextColumn::make('phone')
                    ->searchable(),

                TextColumn::make('province.name')
                    ->label(__('Province'))
                    ->getStateUsing(fn ($record) => $record->province?->getTranslation('name', app()->getLocale()))
                    ->sortable(),

                TextColumn::make('district.name')
                    ->label(__('District'))
                    ->getStateUsing(fn ($record) => $record->district?->getTranslation('name', app()->getLocale()))
                    ->sortable(),

                TextColumn::make('full_address')
                    ->label(__('Full Address'))
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->full_address)
                    ->wrap(),

                TextColumn::make('tax_office')
                    ->label(__('Tax Office'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('tax_number')
                    ->label(__('Tax Number'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::FourExtraLarge),
            ])
            ->paginated(false);
    }
}
