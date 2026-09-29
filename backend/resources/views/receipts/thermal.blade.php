@php
    $money = fn ($value) => number_format((float) $value, 2);
    $store = config('inventory.store');
    $interstate = $order->is_interstate;
    $units = $order->items->sum('quantity');

    // GST summary per rate, from the amounts stored on each line.
    $gstRows = $order->items
        ->groupBy(fn ($item) => (string) (float) $item->tax_percent)
        ->map(fn ($items, $rate) => [
            'rate' => $rate,
            'taxable' => $items->sum('line_subtotal'),
            'cgst' => $items->sum('cgst_amount'),
            'sgst' => $items->sum('sgst_amount'),
            'igst' => $items->sum('igst_amount'),
        ])
        ->sortBy('rate', SORT_NUMERIC);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Bill {{ $order->order_number }}</title>
    <style>
        @page { margin: 8pt 10pt; }
        body { font-family: "DejaVu Sans Mono", monospace; font-size: 8pt; color: #000; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1pt 0; vertical-align: top; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .small { font-size: 7pt; }
        .store { font-size: 12pt; font-weight: bold; }
        .title { font-weight: bold; letter-spacing: 2pt; margin: 3pt 0; }
        .rule { border-top: 1px dashed #000; margin: 4pt 0; }
        .double { border-top: 3px double #000; margin: 4pt 0; }
        .total td { font-size: 11pt; font-weight: bold; }
        .gst th { font-size: 7pt; text-align: right; border-bottom: 1px dashed #000; }
        .gst th:first-child, .gst td:first-child { text-align: left; }
        .gst td { font-size: 7pt; text-align: right; }
    </style>
</head>
<body>
    <div class="center">
        <div class="store">{{ config('app.name') }}</div>
        @if ($store['address']) <div class="small">{{ $store['address'] }}</div> @endif
        @if ($store['phone']) <div class="small">Ph: {{ $store['phone'] }}</div> @endif
        @if ($store['gstin']) <div class="small">GSTIN: {{ $store['gstin'] }}</div> @endif
    </div>

    <div class="rule"></div>
    <div class="center title">TAX INVOICE</div>

    <table>
        <tr><td>Bill No</td><td class="right bold">{{ $order->order_number }}</td></tr>
        <tr><td>{{ $order->created_at->format('d/m/Y') }}</td><td class="right">{{ $order->created_at->format('h:i A') }}</td></tr>
        @if ($order->cashier) <tr><td>Cashier</td><td class="right">{{ $order->cashier->name }}</td></tr> @endif
        <tr><td>Customer</td><td class="right">{{ $order->customer->name }}</td></tr>
        @if ($order->customer->phone) <tr><td>Mobile</td><td class="right">{{ $order->customer->phone }}</td></tr> @endif
        <tr><td>Supply</td><td class="right">{{ $interstate ? 'Inter-state (IGST)' : config('inventory.home_state').' (CGST+SGST)' }}</td></tr>
    </table>

    <div class="rule"></div>
    <table><tr class="bold small"><td>ITEM / QTY x RATE</td><td class="right">AMOUNT</td></tr></table>
    <div class="rule"></div>

    <table>
        @foreach ($order->items as $item)
            <tr><td colspan="2" class="bold">{{ $item->product->name }}</td></tr>
            <tr>
                <td>{{ $item->quantity }} x {{ $money($item->unit_price) }} <span class="small">GST {{ (float) $item->tax_percent }}%</span></td>
                <td class="right">{{ $money($item->line_subtotal) }}</td>
            </tr>
        @endforeach
    </table>

    <div class="rule"></div>
    <table>
        <tr class="small"><td>Items: {{ $order->items->count() }}</td><td class="right">Qty: {{ $units }}</td></tr>
        <tr><td>Subtotal</td><td class="right">{{ $money($order->subtotal) }}</td></tr>
        @if ($interstate)
            <tr><td>IGST</td><td class="right">{{ $money($order->igst_amount) }}</td></tr>
        @else
            <tr><td>CGST</td><td class="right">{{ $money($order->cgst_amount) }}</td></tr>
            <tr><td>SGST</td><td class="right">{{ $money($order->sgst_amount) }}</td></tr>
        @endif
    </table>

    <div class="double"></div>
    <table><tr class="total"><td>TOTAL</td><td class="right">Rs. {{ $money($order->grand_total) }}</td></tr></table>
    <div class="double"></div>

    @if ($order->amount_paid !== null)
        <table>
            <tr><td>Cash</td><td class="right">{{ $money($order->amount_paid) }}</td></tr>
            <tr class="bold"><td>Change</td><td class="right">{{ $money($order->change_due) }}</td></tr>
        </table>
        <div class="rule"></div>
    @endif

    <div class="bold small">GST SUMMARY</div>
    <table class="gst">
        <tr>
            <th>Rate</th><th>Taxable</th>
            @if ($interstate) <th>IGST</th> @else <th>CGST</th><th>SGST</th> @endif
        </tr>
        @foreach ($gstRows as $row)
            <tr>
                <td>{{ $row['rate'] }}%</td>
                <td>{{ $money($row['taxable']) }}</td>
                @if ($interstate)
                    <td>{{ $money($row['igst']) }}</td>
                @else
                    <td>{{ $money($row['cgst']) }}</td>
                    <td>{{ $money($row['sgst']) }}</td>
                @endif
            </tr>
        @endforeach
    </table>

    <div class="rule"></div>
    <div class="center bold">Thank you! Visit again</div>
    <div class="center small">Goods once sold can't be exchanged or returned.</div>
</body>
</html>
