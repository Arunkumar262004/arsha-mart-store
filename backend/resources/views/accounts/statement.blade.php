{{--
    Accounts statements (trial balance, P&L, balance sheet, GSTR-3B) as A4 PDF.
    $blocks: list of
      ['type' => 'table', 'heading' => ?string, 'columns' => [[label, 'left'|'right']], 'rows' => [['cells' => [...], 'bold' => bool, 'indent' => bool]]]
      ['type' => 'sides', 'heading' => ?string, 'left' => [title, rows], 'right' => [title, rows], 'total' => string]
        where rows are ['label' => ..., 'amount' => ?string, 'bold' => bool, 'indent' => bool]
--}}
@php
    $money = fn ($v) => $v === null || $v === '' ? '' : number_format((float) $v, 2);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28pt 28pt 36pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #0f172a; }
        h1 { font-size: 15pt; margin: 0; }
        h2 { font-size: 10pt; margin: 14pt 0 6pt; }
        .muted { color: #64748b; }
        .header { border-bottom: 2px solid #4f46e5; padding-bottom: 6pt; margin-bottom: 10pt; }
        table.data { width: 100%; border-collapse: collapse; border: 1px solid #94a3b8; }
        table.data th { background: #eef2ff; text-align: left; font-size: 7pt; text-transform: uppercase; color: #334155; padding: 4pt; border: 1px solid #94a3b8; }
        table.data td { padding: 3pt 4pt; border: 1px solid #cbd5e1; vertical-align: top; }
        table.data tr { page-break-inside: avoid; }
        tr.bold td { font-weight: bold; background: #f8fafc; }
        td.indent { padding-left: 16pt; color: #475569; }
        .right { text-align: right; }
        table.sides { width: 100%; border-collapse: collapse; }
        table.sides > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }
        table.sides > tbody > tr > td:first-child { padding-right: 4pt; }
        table.sides > tbody > tr > td:last-child { padding-left: 4pt; }
        .note { margin-top: 8pt; font-size: 7pt; color: #64748b; }
        .warn { color: #b45309; }
        .footer { position: fixed; bottom: -22pt; left: 0; right: 0; font-size: 7pt; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="footer">{{ config('app.name') }} · {{ $title }} · generated {{ now()->format('d M Y, h:i A') }}</div>

    <div class="header">
        <h1>{{ config('app.name') }} — {{ $title }}</h1>
        <div class="muted">{{ $subtitle }} · Store: {{ $store }}</div>
    </div>

    @foreach ($blocks as $block)
        @if (! empty($block['heading']))
            <h2>{{ $block['heading'] }}</h2>
        @endif

        @if ($block['type'] === 'table')
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($block['columns'] as [$label, $align])
                            <th class="{{ $align === 'right' ? 'right' : '' }}">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($block['rows'] as $row)
                        <tr class="{{ ($row['bold'] ?? false) ? 'bold' : '' }}">
                            @foreach ($row['cells'] as $i => $value)
                                @php $right = $block['columns'][$i][1] === 'right'; @endphp
                                <td class="{{ $right ? 'right' : '' }} {{ $i === 0 && ($row['indent'] ?? false) ? 'indent' : '' }}">{{ $right ? $money($value) : $value }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <table class="sides">
                <tr>
                    @foreach (['left', 'right'] as $side)
                        <td>
                            <table class="data">
                                <thead><tr><th>{{ $block[$side]['title'] }}</th><th class="right">Amount</th></tr></thead>
                                <tbody>
                                    @foreach ($block[$side]['rows'] as $row)
                                        <tr class="{{ ($row['bold'] ?? false) ? 'bold' : '' }}">
                                            <td class="{{ ($row['indent'] ?? false) ? 'indent' : '' }}">{{ $row['label'] }}</td>
                                            <td class="right">{{ $money($row['amount']) }}</td>
                                        </tr>
                                    @endforeach
                                    <tr class="bold"><td>Total</td><td class="right">{{ $money($block['total']) }}</td></tr>
                                </tbody>
                            </table>
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
    @endforeach

    @foreach ($notes ?? [] as $note)
        <p class="note">{{ $note }}</p>
    @endforeach
</body>
</html>
