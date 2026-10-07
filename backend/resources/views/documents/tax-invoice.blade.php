@php
    $money = fn ($value) => number_format((float) $value, 2);
    $rate = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $s = $invoice['store'];
    $c = $invoice['customer'];
    $t = $invoice['totals'];
    $igst = $invoice['is_interstate'];
    $exempt = $invoice['title'] === 'BILL OF SUPPLY';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice['title'] }} {{ $invoice['invoice_number'] }}</title>
    <style>
        @page { margin: 24pt 28pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .right { text-align: right; }
        .center { text-align: center; }
        .muted { color: #555; }
        .bold { font-weight: bold; }
        .head td { padding: 0; }
        .store { font-size: 15pt; font-weight: bold; }
        .title { font-size: 12pt; font-weight: bold; letter-spacing: 1.5pt; }
        .box { border: 1px solid #333; }
        .box td { padding: 5pt 6pt; }
        .label { font-size: 7pt; text-transform: uppercase; color: #555; letter-spacing: .5pt; }
        .lines th { background: #eee; border: 1px solid #333; padding: 4pt 3pt; font-size: 7.5pt; }
        .lines td { border-left: 1px solid #333; border-right: 1px solid #333; padding: 3pt; }
        .lines tr.last td { border-bottom: 1px solid #333; }
        .lines tfoot td { border: 1px solid #333; font-weight: bold; background: #f6f6f6; }
        .summary th { border: 1px solid #333; padding: 3pt; font-size: 7.5pt; background: #eee; }
        .summary td { border: 1px solid #333; padding: 3pt; font-size: 7.5pt; }
        .totals td { padding: 2pt 6pt; }
        .grand td { font-size: 11pt; font-weight: bold; border-top: 1px solid #333; padding-top: 4pt; }
        .sign { height: 50pt; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            {{-- Embedded so dompdf never fetches a URL. --}}
            <td style="width: 58pt; padding-right: 8pt">
                <img src="{{ \App\Support\Branding::pdfLogo() }}" alt="" style="width: 52pt">
            </td>
            <td style="width: 55%">
                <div class="store">{{ $s['name'] }}</div>
                @if ($s['branch']) <div class="muted">Branch: {{ $s['branch'] }}</div> @endif
                @if ($s['address']) <div>{{ $s['address'] }}@if ($s['city']), {{ $s['city'] }}@endif @if ($s['pincode']) - {{ $s['pincode'] }}@endif</div> @endif
                @if ($s['phone']) <div>Ph: {{ $s['phone'] }}@if ($s['email']) &middot; {{ $s['email'] }}@endif</div> @endif
                @if ($s['gstin']) <div class="bold">GSTIN: {{ $s['gstin'] }}@if ($s['pan']) &middot; PAN: {{ $s['pan'] }}@endif</div> @endif
                @if ($s['state']) <div>State: {{ $s['state'] }}@if ($s['state_code']) (Code {{ $s['state_code'] }})@endif</div> @endif
            </td>
            <td class="right">
                <div class="title">{{ $invoice['title'] }}</div>
                <div class="muted">{{ $invoice['copy'] }}</div>
            </td>
        </tr>
    </table>

    <table class="box" style="margin-top: 10pt">
        <tr>
            <td style="width: 50%; border-right: 1px solid #333">
                <div class="label">Billed to</div>
                <div class="bold">{{ $c['name'] }}</div>
                @if ($c['billing_address']) <div>{{ $c['billing_address'] }}</div> @endif
                @if ($c['phone']) <div>Ph: {{ $c['phone'] }}</div> @endif
                @if ($c['email']) <div>{{ $c['email'] }}</div> @endif
                @if ($c['gstin']) <div class="bold">GSTIN: {{ $c['gstin'] }}</div> @else <div class="muted">Unregistered (B2C)</div> @endif
            </td>
            <td>
                <table>
                    <tr><td class="label" style="padding: 0 0 2pt">Invoice No</td><td class="right bold" style="padding: 0 0 2pt">{{ $invoice['invoice_number'] }}</td></tr>
                    <tr><td class="label" style="padding: 0 0 2pt">Date</td><td class="right" style="padding: 0 0 2pt">{{ \Illuminate\Support\Carbon::parse($invoice['date'])->format('d/m/Y h:i A') }}</td></tr>
                    <tr><td class="label" style="padding: 0 0 2pt">Place of supply</td><td class="right" style="padding: 0 0 2pt">{{ $invoice['place_of_supply'] ? ($invoice['place_of_supply']['state'] ?? '').' ('.$invoice['place_of_supply']['code'].')' : '-' }}</td></tr>
                    <tr><td class="label" style="padding: 0 0 2pt">Supply</td><td class="right" style="padding: 0 0 2pt">{{ $igst ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)' }}</td></tr>
                    <tr><td class="label" style="padding: 0">Payment</td><td class="right" style="padding: 0">{{ strtoupper($invoice['payment_mode']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 10pt">
        <thead>
            <tr>
                <th>#</th>
                <th style="text-align: left">Item</th>
                <th>HSN</th>
                <th class="right">Qty</th>
                <th class="right">Rate</th>
                <th class="right">Taxable</th>
                @unless ($exempt)
                    @if ($igst)
                        <th class="right">IGST</th>
                    @else
                        <th class="right">CGST</th>
                        <th class="right">SGST</th>
                    @endif
                @endunless
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice['lines'] as $line)
                <tr class="{{ $loop->last ? 'last' : '' }}">
                    <td class="center">{{ $line['sr'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="center">{{ $line['hsn_code'] ?? '-' }}</td>
                    <td class="right">{{ $line['quantity'] }} {{ $line['unit'] }}</td>
                    <td class="right">{{ $money($line['rate']) }}</td>
                    <td class="right">{{ $money($line['taxable_value']) }}</td>
                    @unless ($exempt)
                        @if ($igst)
                            <td class="right">{{ $money($line['igst_amount']) }}<br><span class="muted">{{ $rate($line['igst_percent']) }}%</span></td>
                        @else
                            <td class="right">{{ $money($line['cgst_amount']) }}<br><span class="muted">{{ $rate($line['cgst_percent']) }}%</span></td>
                            <td class="right">{{ $money($line['sgst_amount']) }}<br><span class="muted">{{ $rate($line['sgst_percent']) }}%</span></td>
                        @endif
                    @endunless
                    <td class="right">{{ $money($line['total']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" class="right">Total</td>
                <td class="right">{{ $t['quantity'] }}</td>
                <td></td>
                <td class="right">{{ $money($t['taxable_value']) }}</td>
                @unless ($exempt)
                    @if ($igst)
                        <td class="right">{{ $money($t['igst']) }}</td>
                    @else
                        <td class="right">{{ $money($t['cgst']) }}</td>
                        <td class="right">{{ $money($t['sgst']) }}</td>
                    @endif
                @endunless
                <td class="right">{{ $money($t['grand_total']) }}</td>
            </tr>
        </tfoot>
    </table>

    <table style="margin-top: 10pt">
        <tr>
            <td style="width: 58%; padding-right: 10pt">
                @unless ($exempt)
                    <div class="label" style="margin-bottom: 3pt">HSN-wise tax summary</div>
                    <table class="summary">
                        <tr>
                            <th>HSN</th><th>Rate</th><th class="right">Taxable</th>
                            @if ($igst) <th class="right">IGST</th> @else <th class="right">CGST</th><th class="right">SGST</th> @endif
                            <th class="right">Total tax</th>
                        </tr>
                        @foreach ($invoice['hsn_summary'] as $row)
                            <tr>
                                <td>{{ $row['hsn_code'] ?? '-' }}</td>
                                <td>{{ $rate($row['tax_percent']) }}%</td>
                                <td class="right">{{ $money($row['taxable_value']) }}</td>
                                @if ($igst)
                                    <td class="right">{{ $money($row['igst_amount']) }}</td>
                                @else
                                    <td class="right">{{ $money($row['cgst_amount']) }}</td>
                                    <td class="right">{{ $money($row['sgst_amount']) }}</td>
                                @endif
                                <td class="right">{{ $money($row['tax']) }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endunless
                <div class="label" style="margin-top: 8pt">Amount in words</div>
                <div class="bold">{{ $invoice['amount_in_words'] }}</div>
            </td>
            <td>
                <table class="totals box">
                    <tr><td>Taxable value</td><td class="right">{{ $money($t['taxable_value']) }}</td></tr>
                    @unless ($exempt)
                        @if ($igst)
                            <tr><td>IGST</td><td class="right">{{ $money($t['igst']) }}</td></tr>
                        @else
                            <tr><td>CGST</td><td class="right">{{ $money($t['cgst']) }}</td></tr>
                            <tr><td>SGST</td><td class="right">{{ $money($t['sgst']) }}</td></tr>
                        @endif
                    @endunless
                    <tr class="grand"><td>Grand total</td><td class="right">Rs. {{ $money($t['grand_total']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 24pt">
        <tr>
            <td style="width: 60%" class="muted">
                @if ($exempt) Supply of goods exempt from GST. @endif
                Goods once sold can't be exchanged or returned.<br>
                This is a computer-generated invoice.
            </td>
            <td class="right">
                <div class="bold">For {{ $s['name'] }}</div>
                <div class="sign"></div>
                <div>Authorised signatory</div>
            </td>
        </tr>
    </table>
</body>
</html>
