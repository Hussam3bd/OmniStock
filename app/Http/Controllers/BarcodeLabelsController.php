<?php

namespace App\Http\Controllers;

use App\Models\Product\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BarcodeLabelsController extends Controller
{
    public function print(Request $request): Response
    {
        $validated = $request->validate([
            'variants' => ['required', 'string'],
            'width' => ['required', 'numeric', 'min:20', 'max:300'],
            'height' => ['required', 'numeric', 'min:10', 'max:300'],
        ]);

        /** @var array<int, int> $variantQuantities */
        $variantQuantities = json_decode($validated['variants'], true);

        if (! is_array($variantQuantities) || empty($variantQuantities)) {
            abort(422, 'No variants provided.');
        }

        $variants = ProductVariant::query()
            ->whereIn('id', array_keys($variantQuantities))
            ->with(['product', 'optionValues.variantOption'])
            ->get()
            ->keyBy('id');

        $labels = [];

        foreach ($variantQuantities as $variantId => $quantity) {
            $variant = $variants->get($variantId);
            if (! $variant) {
                continue;
            }

            $options = $variant->optionValues
                ->sortBy(fn ($ov) => $ov->variantOption?->position ?? 0)
                ->map(fn ($ov) => $ov->getTranslation('value', 'tr'))
                ->values()
                ->take(3)
                ->implode(' / ');

            for ($i = 0; $i < (int) $quantity; $i++) {
                $labels[] = [
                    'sku' => $variant->sku ?? '',
                    'barcode' => $variant->barcode ?? $variant->sku ?? '',
                    'options' => $options,
                ];
            }
        }

        return response()->view('products.barcode-labels', [
            'labels' => $labels,
            'width' => (float) $validated['width'],
            'height' => (float) $validated['height'],
        ]);
    }
}
