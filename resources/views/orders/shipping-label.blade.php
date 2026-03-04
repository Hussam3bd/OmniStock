<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kargo Etiketi — {{ $order->order_number }}</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            width: 100mm;
            height: 100mm;
            overflow: hidden;
            background: #fff;
            font-family: Arial, Helvetica, sans-serif;
        }

        body {
            padding: 2.5mm;
            display: flex;
            flex-direction: column;
            gap: 1.5mm;
        }

        .section {
            border: 1.5px solid #000;
            border-radius: 1.5mm;
            padding: 2mm 2.5mm;
        }

        .section-label {
            font-size: 6pt;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 1mm;
            letter-spacing: 0.3px;
        }

        /* ALICI row: left = name/location, right = carrier badge */
        .alici-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 2mm;
        }

        .alici-info {
            flex: 1;
            min-width: 0;
        }

        .recipient-name {
            font-size: 11pt;
            font-weight: 900;
            line-height: 1.15;
        }

        .recipient-location {
            font-size: 8pt;
            font-weight: 700;
            margin-top: 0.5mm;
        }

        .carrier-badge {
            flex-shrink: 0;
            border: 1.5px solid #000;
            border-radius: 1.5mm;
            padding: 1.5mm 2mm;
            font-size: 7pt;
            font-weight: 900;
            text-align: center;
            line-height: 1.3;
            white-space: nowrap;
            align-self: center;
        }

        /* KARGO NOTE */
        .note-text {
            font-size: 7.5pt;
            font-weight: 700;
            line-height: 1.4;
            margin-bottom: 1.5mm;
        }

        .barcode-wrapper {
            text-align: center;
        }

        #barcode {
            width: 100%;
        }

        /* ORDER INFO */
        .order-section {
            flex: 1;
            overflow: hidden;
        }

        .order-number {
            font-size: 7.5pt;
            font-weight: 900;
            margin-bottom: 1mm;
        }

        .items-list {
            display: flex;
            flex-direction: column;
            gap: 1mm;
        }

        .item-row {
            font-size: 6.5pt;
            font-weight: 600;
            line-height: 1.35;
        }

        .item-sku {
            font-size: 6pt;
            font-weight: 400;
            color: #555;
            font-family: monospace;
        }

        /* Footer */
        .footer {
            text-align: center;
            font-size: 6pt;
            color: #888;
            letter-spacing: 0.5px;
            padding-top: 0.5mm;
        }

        @media print {
            @page {
                size: 100mm 100mm;
                margin: 0;
            }

            html, body {
                width: 100mm;
                height: 100mm;
            }
        }
    </style>
</head>
<body>
    @php
        $address   = $order->shippingAddress;
        $firstName = strtoupper($address?->first_name ?? $order->customer?->first_name ?? '');
        $lastName  = strtoupper($address?->last_name  ?? $order->customer?->last_name  ?? '');
        $fullName  = trim("{$firstName} {$lastName}");
        $province  = $address?->province?->name ?? '';
        $district  = $address?->district?->name ?? '';
    @endphp

    {{-- ALICI + carrier badge --}}
    <div class="section">
        <div class="section-label">Alıcı:</div>
        <div class="alici-row">
            <div class="alici-info">
                <div class="recipient-name">{{ $fullName ?: '—' }}</div>
                @if ($province || $district)
                    <div class="recipient-location">
                        {{ implode(' / ', array_filter([$province, $district])) }}
                    </div>
                @endif
            </div>
            @if ($carrierLabel)
                <div class="carrier-badge">{{ strtoupper($carrierLabel) }}</div>
            @endif
        </div>
    </div>

    {{-- KARGO PERSONELİNE NOT + barcode --}}
    <div class="section">
        <div class="section-label">Kargo Personeline Not:</div>
        <div class="note-text">{{ $note }}</div>
        <div class="barcode-wrapper">
            <svg id="barcode"></svg>
        </div>
    </div>

    {{-- Sipariş + ürünler --}}
    <div class="section order-section">
        <div class="order-number">Sipariş No: #{{ $order->order_number }}</div>
        <div class="items-list">
            @foreach ($order->items as $item)
                @php
                    $variant     = $item->productVariant;
                    $productName = $variant?->product?->name ?? 'Ürün';
                    $options     = $variant?->optionValues->map(fn ($ov) => $ov->value)->implode(' / ');
                    $sku         = $variant?->sku;
                @endphp
                <div class="item-row">
                    • {{ $productName }}
                    @if ($options) - {{ $options }} @endif
                    (Adet: {{ $item->quantity }})
                    @if ($sku)
                        <br><span class="item-sku">SKU: {{ $sku }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="footer">revanstep.com</div>

    <script>
        JsBarcode('#barcode', @json($trackingNumber), {
            format: 'CODE128',
            displayValue: true,
            width: 1.6,
            height: 38,
            margin: 1,
            fontSize: 11,
            textMargin: 2,
        });

        window.addEventListener('load', () => window.print());
    </script>
</body>
</html>
