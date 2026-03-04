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
            background: #fff;
            font-family: Arial, Helvetica, sans-serif;
        }

        /* Fixed-width label wrapper — Safari ignores @page size so we
           control dimensions here and scale to fit the actual paper. */
        .label-root {
            width: 100mm;
            padding: 4mm;
            display: flex;
            flex-direction: column;
            gap: 3mm;
        }

        .section {
            border: 1.5px solid #000;
            border-radius: 2mm;
            padding: 3mm;
        }

        .section-label {
            font-size: 7.5pt;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 1.5mm;
        }

        /* ALICI */
        .recipient-name {
            font-size: 14pt;
            font-weight: 900;
            line-height: 1.2;
        }

        .recipient-location {
            font-size: 10pt;
            font-weight: 700;
            margin-top: 1mm;
        }

        /* KARGO NOTE */
        .note-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 2mm;
            margin-bottom: 2mm;
        }

        .note-text {
            font-size: 8pt;
            font-weight: 700;
            line-height: 1.5;
            flex: 1;
        }

        .carrier-badge {
            flex-shrink: 0;
            border: 1.5px solid #000;
            border-radius: 1.5mm;
            padding: 1.5mm 2.5mm;
            font-size: 8pt;
            font-weight: 900;
            text-align: center;
            line-height: 1.3;
            white-space: nowrap;
        }

        .barcode-wrapper {
            text-align: center;
            margin-top: 1mm;
        }

        #barcode {
            width: 100%;
        }

        /* SİPARİŞ BİLGİLERİ */
        .order-number {
            font-size: 9pt;
            font-weight: 900;
            margin-bottom: 2mm;
        }

        .items-list {
            display: flex;
            flex-direction: column;
            gap: 1mm;
        }

        .item-row {
            font-size: 7.5pt;
            font-weight: 600;
            line-height: 1.4;
        }

        @media print {
            @page {
                size: 100mm auto;
                margin: 0mm;
            }

            html, body {
                margin: 0;
                padding: 0;
                /* Exact colour rendering in both Chrome and Safari */
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .label-root {
                width: 100mm;
            }
        }
    </style>
</head>
<body>
<div class="label-root">
    @php
        $address   = $order->shippingAddress;
        $firstName = strtoupper($address?->first_name ?? $order->customer?->first_name ?? '');
        $lastName  = strtoupper($address?->last_name  ?? $order->customer?->last_name  ?? '');
        $fullName  = trim("{$firstName} {$lastName}");
        $province  = $address?->province?->name ?? '';
        $district  = $address?->district?->name ?? '';

        $carrierLabel = $order->shipping_carrier?->getLabel() ?? 'KARGO';
    @endphp

    {{-- ALICI --}}
    <div class="section">
        <div class="section-label">Alıcı:</div>
        <div class="recipient-name">{{ $fullName ?: 'İSİM YOK' }}</div>
        @if ($province || $district)
            <div class="recipient-location">
                {{ implode(' / ', array_filter([$province, $district])) }}
            </div>
        @endif
    </div>

    {{-- KARGO PERSONELİNE NOT --}}
    <div class="section">
        <div class="section-label">Kargo Personeline Not:</div>
        <div class="note-row">
            <div class="note-text">Pazaryeri siparişi olarak işleme alınmalıdır!</div>
            <div class="carrier-badge">{{ strtoupper($carrierLabel) }}<br>KARGO</div>
        </div>
        <div class="barcode-wrapper">
            <svg id="barcode"></svg>
        </div>
    </div>

    {{-- SİPARİŞ BİLGİLERİ --}}
    <div class="section">
        <div class="section-label">Sipariş Bilgileri:</div>
        <div class="order-number">Sipariş No: #{{ $order->order_number }}</div>
        <div class="items-list">
            @foreach ($order->items as $item)
                @php
                    $productName = $item->productVariant?->product?->name ?? 'Ürün';
                    $options = $item->productVariant?->optionValues
                        ->map(fn ($ov) => $ov->value)
                        ->implode(' / ');
                @endphp
                <div class="item-row">
                    • {{ $productName }}
                    @if ($options)
                        - {{ $options }}
                    @endif
                    (Adet: {{ $item->quantity }})
                </div>
            @endforeach
        </div>
    </div>

    <script>
        JsBarcode('#barcode', @json($trackingNumber), {
            format: 'CODE128',
            displayValue: true,
            width: 2,
            height: 50,
            margin: 2,
            fontSize: 14,
            textMargin: 3,
        });

        // Safari renders SVGs asynchronously — a small delay ensures the
        // barcode is fully painted before the print dialog opens.
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</div>
</body>
</html>
