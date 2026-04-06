<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('Barcode Labels') }} — {{ count($labels) }}</title>
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
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .labels-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0;
        }

        .label {
            width: {{ $width }}mm;
            height: {{ $height }}mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: {{ $height >= 35 ? '2mm 3mm' : '1.5mm 2mm' }};
            page-break-inside: avoid;
        }

        .label-sku {
            font-size: {{ $height >= 35 ? '10pt' : '8.5pt' }};
            font-weight: 900;
            text-align: center;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            flex-shrink: 0;
        }

        .label-options {
            font-size: {{ $height >= 35 ? '8pt' : '6.5pt' }};
            font-weight: 600;
            text-align: center;
            color: #333;
            line-height: 1.2;
            margin-top: 0.5mm;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            flex-shrink: 0;
        }

        .label-barcode {
            flex: 1;
            display: flex;
            align-items: stretch;
            justify-content: center;
            width: 100%;
            min-height: 0;
            margin-top: 1mm;
        }

        .label-barcode svg {
            width: 100%;
            height: 100%;
        }

        @media print {
            @page {
                size: {{ $width }}mm {{ $height }}mm;
                margin: 0;
                padding: 0;
            }

            html, body {
                width: {{ $width }}mm;
                margin: 0;
                padding: 0;
            }

            .labels-grid {
                display: block;
            }

            .label {
                width: {{ $width }}mm;
                height: {{ $height }}mm;
                page-break-after: always;
                overflow: hidden;
            }

            .label:last-child {
                page-break-after: auto;
            }
        }

        @media screen {
            body {
                background: #e5e7eb;
                padding: 20px;
            }

            .labels-grid {
                max-width: 900px;
                margin: 0 auto;
                gap: 4px;
                justify-content: center;
            }

            .label {
                background: #fff;
                border: 1px dashed #d1d5db;
            }
        }
    </style>
</head>
<body>

<div class="labels-grid">
    @foreach($labels as $index => $label)
        <div class="label">
            <div class="label-sku">{{ $label['sku'] }}</div>
            @if($label['options'])
                <div class="label-options">{{ $label['options'] }}</div>
            @endif
            <div class="label-barcode">
                <svg id="barcode-{{ $index }}"></svg>
            </div>
        </div>
    @endforeach
</div>

@php
    $barcodeData = collect($labels)->map(fn($l, $i) => [
        'id' => 'barcode-' . $i,
        'value' => $l['barcode'],
    ])->values();
@endphp

<script>
    const labels = @json($barcodeData);

    const labelHeight = {{ $height }};
    const fontSize = labelHeight >= 35 ? 10 : 8;

    window.addEventListener('load', () => {
        labels.forEach(({ id, value }) => {
            if (!value) return;
            try {
                const el = document.querySelector('#' + id);
                const container = el.parentElement;
                const availableHeight = container.clientHeight;
                // Reserve space for the number text below the bars
                const barHeight = Math.max(10, availableHeight - fontSize - 4);

                JsBarcode('#' + id, value, {
                    format: 'CODE128',
                    displayValue: true,
                    width: 1.8,
                    height: barHeight,
                    margin: 0,
                    fontSize: fontSize,
                    textMargin: 1,
                    flat: true,
                });

                // After rendering, let SVG fill container
                el.removeAttribute('width');
                el.removeAttribute('height');
                el.style.width = '100%';
                el.style.height = '100%';
            } catch (e) {
                console.warn('Barcode error for ' + value, e);
            }
        });

        window.print();
    });
</script>
</body>
</html>
