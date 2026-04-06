<?php

namespace App\Filament\Pages;

use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Settings\BarcodeSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

class PrintBarcodeLabels extends Page
{
    protected string $view = 'filament.pages.print-barcode-labels';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static ?int $navigationSort = 15;

    public static function getNavigationGroup(): ?string
    {
        return __('Products');
    }

    public static function getNavigationLabel(): string
    {
        return __('Print Barcodes');
    }

    public function getTitle(): string
    {
        return __('Print Barcode Labels');
    }

    /** @var array<int, int> variant_id => quantity */
    public array $selectedVariants = [];

    public string $labelPreset = '';

    public float $labelWidth = 76;

    public float $labelHeight = 25.4;

    public function mount(): void
    {
        $settings = app(BarcodeSettings::class);
        $this->labelPreset = $settings->label_preset;
        $this->labelWidth = $settings->label_width;
        $this->labelHeight = $settings->label_height;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addProduct')
                ->label(__('Add Product'))
                ->icon('heroicon-o-cube')
                ->color('gray')
                ->form([
                    Forms\Components\Select::make('product_id')
                        ->label(__('Product'))
                        ->options(fn () => $this->getProductOptions())
                        ->allowHtml()
                        ->searchable()
                        ->preload()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->addProduct($data['product_id']);
                }),

            Action::make('addVariants')
                ->label(__('Add Variants'))
                ->icon('heroicon-o-squares-plus')
                ->color('gray')
                ->form([
                    Forms\Components\Select::make('variant_ids')
                        ->label(__('Variants'))
                        ->options(fn () => $this->getVariantOptions())
                        ->allowHtml()
                        ->searchable()
                        ->preload()
                        ->multiple()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    foreach ($data['variant_ids'] as $variantId) {
                        $this->addVariant((int) $variantId);
                    }
                }),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function getProductOptions(): array
    {
        return Product::query()
            ->with(['variants', 'media'])
            ->whereHas('variants')
            ->get()
            ->mapWithKeys(function (Product $product) {
                $image = $product->getFirstMediaUrl('images', 'thumb') ?: $product->getFirstMediaUrl('images');
                $variantCount = $product->variants->count();

                $html = '<div class="flex items-center gap-3">';
                $html .= $this->renderImageHtml($image);
                $html .= '<div class="min-w-0">';
                $html .= '<div class="text-sm font-medium truncate">'.e($product->model_code ?: $product->title).'</div>';
                $html .= '<div class="text-xs text-gray-500 truncate">'.e($product->title).' &middot; '.$variantCount.' '.__('variants').'</div>';
                $html .= '</div></div>';

                return [$product->id => $html];
            })
            ->toArray();
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function getVariantOptions(): array
    {
        return ProductVariant::query()
            ->with(['product.media', 'optionValues.variantOption'])
            ->get()
            ->groupBy(fn (ProductVariant $v) => $v->product?->title.($v->product?->model_code ? ' ('.$v->product->model_code.')' : ''))
            ->map(fn ($variants) => $variants->mapWithKeys(function (ProductVariant $variant) {
                $image = $variant->product?->getFirstMediaUrl('images', 'thumb') ?: $variant->product?->getFirstMediaUrl('images');
                $options = $variant->optionValues
                    ->sortBy(fn ($ov) => $ov->variantOption?->position ?? 0)
                    ->map(fn ($ov) => $ov->getTranslation('value', 'tr'))
                    ->implode(' / ');

                $html = '<div class="flex items-center gap-3">';
                $html .= $this->renderImageHtml($image);
                $html .= '<div class="min-w-0">';
                $html .= '<div class="text-sm font-medium truncate">'.e($variant->sku ?? '').'</div>';
                $html .= '<div class="text-xs text-gray-500 truncate">'
                    .($options ? e($options).' &middot; ' : '')
                    .__('Stock').': '.$variant->inventory_quantity
                    .'</div>';
                $html .= '</div></div>';

                return [$variant->id => $html];
            }))
            ->toArray();
    }

    protected function renderImageHtml(?string $image): string
    {
        if ($image) {
            return '<img src="'.e($image).'" class="h-8 w-8 rounded object-cover shrink-0" />';
        }

        return '<div class="h-8 w-8 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center shrink-0">'
            .'<svg class="h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">'
            .'<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>'
            .'</svg></div>';
    }

    public function addProduct(int $productId): void
    {
        $product = Product::with('variants')->find($productId);
        if (! $product) {
            return;
        }

        foreach ($product->variants as $variant) {
            if (! isset($this->selectedVariants[$variant->id])) {
                $this->selectedVariants[$variant->id] = max(1, $variant->inventory_quantity);
            }
        }
    }

    public function addVariant(int $variantId): void
    {
        $variant = ProductVariant::find($variantId);
        if (! $variant || isset($this->selectedVariants[$variantId])) {
            return;
        }

        $this->selectedVariants[$variantId] = max(1, $variant->inventory_quantity);
    }

    public function removeVariant(int $variantId): void
    {
        unset($this->selectedVariants[$variantId]);
    }

    public function clearAll(): void
    {
        $this->selectedVariants = [];
    }

    public function updateQuantity(int $variantId, int $quantity): void
    {
        if ($quantity < 1) {
            $quantity = 1;
        }
        $this->selectedVariants[$variantId] = $quantity;
    }

    public function updatedLabelPreset(string $value): void
    {
        $presets = BarcodeSettings::labelPresets();
        if ($value !== 'custom' && isset($presets[$value])) {
            $this->labelWidth = $presets[$value]['width'];
            $this->labelHeight = $presets[$value]['height'];
        }
    }

    #[Computed]
    public function selectedVariantDetails(): array
    {
        if (empty($this->selectedVariants)) {
            return [];
        }

        return ProductVariant::query()
            ->whereIn('id', array_keys($this->selectedVariants))
            ->with(['product.media', 'optionValues.variantOption'])
            ->get()
            ->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'product_title' => $v->product?->title ?? '',
                'model_code' => $v->product?->model_code ?? '',
                'image' => $v->product?->getFirstMediaUrl('images', 'thumb') ?: $v->product?->getFirstMediaUrl('images'),
                'options' => $v->optionValues
                    ->sortBy(fn ($ov) => $ov->variantOption?->position ?? 0)
                    ->map(fn ($ov) => $ov->getTranslation('value', 'tr'))
                    ->values()
                    ->toArray(),
                'stock' => $v->inventory_quantity,
                'quantity' => $this->selectedVariants[$v->id] ?? 1,
            ])
            ->toArray();
    }

    #[Computed]
    public function totalLabels(): int
    {
        return array_sum($this->selectedVariants);
    }

    #[Computed]
    public function labelPresetsOptions(): array
    {
        return collect(BarcodeSettings::labelPresets())->mapWithKeys(
            fn (array $preset, string $key) => [$key => $preset['label']]
        )->toArray();
    }
}
