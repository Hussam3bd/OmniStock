<?php

namespace App\Filament\Resources\Supplier\Suppliers\Pages;

use App\Filament\Resources\Supplier\Suppliers\SupplierResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Schemas\Components\Section::make(__('Supplier Information'))
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label(__('Name'))
                            ->weight(FontWeight::Bold)
                            ->size('lg'),

                        Infolists\Components\TextEntry::make('code')
                            ->label(__('Code')),

                        Infolists\Components\TextEntry::make('email')
                            ->label(__('Email'))
                            ->copyable()
                            ->icon('heroicon-o-envelope'),

                        Infolists\Components\TextEntry::make('phone')
                            ->label(__('Phone'))
                            ->copyable()
                            ->icon('heroicon-o-phone'),

                        Infolists\Components\TextEntry::make('contact_person')
                            ->label(__('Contact Person'))
                            ->placeholder(__('No contact person')),

                        Infolists\Components\TextEntry::make('tax_number')
                            ->label(__('Tax Number'))
                            ->placeholder(__('No tax number')),

                        Infolists\Components\TextEntry::make('city')
                            ->label(__('City')),

                        Infolists\Components\TextEntry::make('country')
                            ->label(__('Country')),

                        Infolists\Components\TextEntry::make('address')
                            ->label(__('Address'))
                            ->columnSpanFull()
                            ->placeholder(__('No address')),

                        Infolists\Components\TextEntry::make('is_active')
                            ->label(__('Status'))
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state ? __('Active') : __('Inactive'))
                            ->color(fn ($state) => $state ? 'success' : 'danger'),

                        Infolists\Components\TextEntry::make('notes')
                            ->label(__('Notes'))
                            ->columnSpanFull()
                            ->placeholder(__('No notes'))
                            ->visible(fn ($record) => $record->notes),
                    ]),

                Schemas\Components\Section::make(__('Purchase Statistics'))
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('total_purchase_orders')
                            ->label(__('Total Purchase Orders'))
                            ->state(function ($record) {
                                return Number::format($record->purchaseOrders()->count());
                            })
                            ->icon('heroicon-o-document-text'),

                        Infolists\Components\TextEntry::make('total_items_purchased')
                            ->label(__('Total Items Purchased'))
                            ->state(function ($record) {
                                $total = DB::table('purchase_order_items')
                                    ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
                                    ->where('purchase_orders.supplier_id', $record->id)
                                    ->sum('purchase_order_items.quantity_received');

                                return Number::format($total);
                            })
                            ->icon('heroicon-o-cube')
                            ->color('info'),

                        Infolists\Components\TextEntry::make('total_value_paid')
                            ->label(__('Total Value Paid'))
                            ->state(function ($record) {
                                // Group by currency and calculate totals
                                $totals = DB::table('purchase_orders')
                                    ->where('supplier_id', $record->id)
                                    ->whereNotIn('status', ['draft', 'cancelled'])
                                    ->select('currency_code', DB::raw('SUM(total) as total'))
                                    ->groupBy('currency_code')
                                    ->get();

                                // Format each currency total
                                return $totals->map(function ($item) {
                                    return money($item->total, $item->currency_code)->format();
                                })->join(' + ');
                            })
                            ->icon('heroicon-o-banknotes')
                            ->color('success'),
                    ]),
            ]);
    }
}
