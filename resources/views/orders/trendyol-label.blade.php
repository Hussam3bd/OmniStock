<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trendyol Kargo Etiketi — {{ $order->order_number }}</title>
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
            padding: 3mm;
            display: flex;
            flex-direction: column;
        }

        .label-wrapper {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        /* Top barcode */
        .barcode-top {
            width: 100%;
            display: block;
        }

        .barcode-number-top {
            text-align: center;
            font-size: 9pt;
            font-weight: 900;
            letter-spacing: 0.5px;
            margin: 1mm 0 2mm;
        }

        /* Main content row */
        .content-row {
            display: flex;
            gap: 3mm;
            flex: 1;
        }

        .content-left {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2mm;
        }

        .box {
            border: 2px solid #000;
            padding: 2mm;
        }

        .box p {
            font-size: 7.5pt;
            font-weight: 900;
            line-height: 1.4;
            text-transform: uppercase;
        }

        .info {
            margin-top: 1mm;
        }

        .info p {
            font-size: 7.5pt;
            font-weight: 900;
            line-height: 1.7;
        }

        .tracking-highlight {
            background: #ffff00;
            display: inline-block;
            padding: 0 1mm;
        }

        /* Right barcode — dimensions and transform set by JS */
        #barcode-right-wrapper {
            position: relative;
            flex-shrink: 0;
            overflow: hidden;
        }

        #barcode-right {
            position: absolute;
            top: 0;
            left: 0;
            transform-origin: top left;
            display: block;
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
    <div class="label-wrapper">

        {{-- Top barcode --}}
        <svg id="barcode-top" class="barcode-top"></svg>
        <div class="barcode-number-top">{{ $trackingNumber }}</div>

        <div class="content-row">
            <div class="content-left">

                {{-- Shipping address box --}}
                @php
                    $address  = $order->shippingAddress;
                    $line1    = strtoupper($address?->address_line1 ?? '');
                    $neighbor = strtoupper($address?->neighborhood?->name ?? '');
                    $district = strtoupper($address?->district?->name ?? '');
                    $province = strtoupper($address?->province?->name ?? '');
                @endphp

                <div class="box">
                    @if ($line1)
                        <p>{{ $line1 }}</p>
                    @endif
                    @if ($district && $province)
                        <p>{{ $district }} / {{ $province }}</p>
                    @endif
                    @if ($province)
                        <p>{{ $province }}</p>
                    @endif
                    @if ($neighbor)
                        <p>{{ $neighbor }}</p>
                    @endif
                </div>

                {{-- Customer name box --}}
                <div class="box">
                    @php
                        $firstName = strtoupper($address?->first_name ?? $order->customer?->first_name ?? '');
                        $lastName  = strtoupper($address?->last_name  ?? $order->customer?->last_name  ?? '');
                    @endphp
                    <p>{{ trim("{$firstName} {$lastName}") }}</p>
                </div>

                {{-- Order info --}}
                <div class="info">
                    <p>{{ $order->order_number }}</p>
                    <p><span class="tracking-highlight">{{ $trackingNumber }}</span></p>
                    @if ($order->shipping_carrier)
                        @php
                            $carrierLabel = strtoupper($order->shipping_carrier->getLabel());
                            $suffix = $order->shipping_carrier->value === 'dhl' ? '' : ' KARGO';
                        @endphp
                        <p>{{ $carrierLabel }}{{ $suffix }}</p>
                    @endif
                </div>

            </div>

            {{-- Right-side vertical barcode — sized by JS after render --}}
            <div id="barcode-right-wrapper">
                <svg id="barcode-right"></svg>
            </div>
        </div>
    </div>

    <script>
        const trackingNumber = @json($trackingNumber);

        JsBarcode('#barcode-top', trackingNumber, {
            format: 'CODE128',
            displayValue: false,
            width: 2,
            height: 45,
            margin: 0,
        });

        JsBarcode('#barcode-right', trackingNumber, {
            format: 'CODE128',
            displayValue: false,
            width: 2,
            height: 40,
            margin: 0,
        });

        (function () {
            const svg     = document.getElementById('barcode-right');
            const wrapper = document.getElementById('barcode-right-wrapper');
            const bw = parseFloat(svg.getAttribute('width'));
            const bh = parseFloat(svg.getAttribute('height'));

            wrapper.style.width  = bh + 'px';
            wrapper.style.height = bw + 'px';
            svg.style.transform  = `translateY(${bw}px) rotate(90deg)`;
        }());

        window.addEventListener('load', () => window.print());
    </script>
</body>
</html>
