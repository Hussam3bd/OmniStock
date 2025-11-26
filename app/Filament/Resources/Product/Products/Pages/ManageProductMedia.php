<?php

namespace App\Filament\Resources\Product\Products\Pages;

use App\Filament\Resources\Product\Products\ProductResource;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Table;

class ManageProductMedia extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'variants';

    public static function getNavigationLabel(): string
    {
        return __('Media');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                SpatieMediaLibraryImageColumn::make('images')
                    ->label(__('Image'))
                    ->collection('images')
                    ->conversion('thumb')
                    ->size(60)
                    ->limit(1),

                Tables\Columns\TextColumn::make('sku')
                    ->label(__('SKU'))
                    ->searchable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('optionValues.value')
                    ->label(__('Variant'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => __($state))
                    ->separator(' / ')
                    ->wrap(),

                Tables\Columns\TextColumn::make('media_count')
                    ->label(__('Images'))
                    ->counts('media')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('has_images')
                    ->label(__('Has Images'))
                    ->queries(
                        true: fn ($query) => $query->has('media'),
                        false: fn ($query) => $query->doesntHave('media'),
                    ),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('Manage Images'))
                    ->icon('heroicon-o-photo')
                    ->modalWidth('5xl')
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('images')
                            ->label(__('Images'))
                            ->collection('images')
                            ->multiple()
                            ->reorderable()
                            ->maxFiles(10)
                            ->maxSize(5120) // 5MB
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios([
                                '1:1',
                                '4:3',
                                '16:9',
                            ])
                            ->downloadable()
                            ->openable()
                            ->helperText(__('Upload up to 10 images. Max 5MB per image. Drag to reorder.'))
                            ->columnSpanFull(),

                        Forms\Components\Placeholder::make('info')
                            ->label(__('Information'))
                            ->content(__('Images are automatically optimized and multiple sizes are generated. The first image will be used as the primary image.'))
                            ->columnSpanFull(),
                    ]),
            ])
            ->toolbarActions([
                BulkAction::make('delete_all_images')
                    ->label(__('Delete All Images'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                        foreach ($records as $record) {
                            $record->clearMediaCollection('images');
                        }

                        \Filament\Notifications\Notification::make()
                            ->title(__('All images deleted'))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
