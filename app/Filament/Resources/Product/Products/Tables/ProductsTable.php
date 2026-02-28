<?php

namespace App\Filament\Resources\Product\Products\Tables;

use App\Forms\Components\MoneyInput;
use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('Product Name'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn ($record) => $record->product_type
                        ? __($record->product_type)
                        : null),

                TextColumn::make('variants_count')
                    ->label(__('Variants'))
                    ->counts('variants')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('supplier.name')
                    ->label(__('Supplier'))
                    ->searchable()
                    ->sortable()
                    ->placeholder(__('No supplier'))
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'draft' => 'warning',
                        'archived' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => __(ucfirst($state)))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('update_cost_price')
                        ->label(__('Update Cost Price'))
                        ->icon('heroicon-o-currency-dollar')
                        ->color('warning')
                        ->schema([
                            MoneyInput::make('cost_price')
                                ->label(__('Cost Price'))
                                ->helperText(__('This will update the cost price for all variants of the selected products'))
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $totalVariants = 0;
                            $costPrice = $data['cost_price']->multiply(100)->getAmount();

                            foreach ($records as $product) {
                                $variantsCount = $product->variants()->count();
                                $product->variants()->update([
                                    'cost_price' => $costPrice,
                                ]);
                                $totalVariants += $variantsCount;
                            }

                            Notification::make()
                                ->success()
                                ->title(__('Cost price updated successfully'))
                                ->body(__('Updated cost price for :count variants across :products products', [
                                    'count' => $totalVariants,
                                    'products' => $records->count(),
                                ]))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('sync_trendyol_stock')
                        ->label(__('Sync Stock to Trendyol'))
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(__('Sync Stock to Trendyol'))
                        ->modalDescription(__('This will push current stock quantities for all variants of the selected products to Trendyol. Trendyol prices will be preserved.'))
                        ->modalSubmitActionLabel(__('Sync Stock'))
                        ->visible(function () {
                            return Integration::where('provider', 'trendyol')
                                ->where('is_active', true)
                                ->exists();
                        })
                        ->action(function (Collection $records): void {
                            $integration = Integration::where('provider', 'trendyol')
                                ->where('is_active', true)
                                ->first();

                            $adapter = new TrendyolAdapter($integration);
                            $variants = $records->loadMissing('variants')->pluck('variants')->flatten();

                            try {
                                $result = $adapter->syncStock($variants);

                                if ($result['success']) {
                                    Notification::make()
                                        ->title(__('Stock synced to Trendyol'))
                                        ->body(__(':synced variants synced, :skipped skipped across :products products', [
                                            'synced' => $result['synced'],
                                            'skipped' => $result['skipped'],
                                            'products' => $records->count(),
                                        ]))
                                        ->success()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title(__('Stock sync completed with errors'))
                                        ->body(implode('; ', $result['errors']))
                                        ->warning()
                                        ->send();
                                }
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title(__('Error syncing stock'))
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
