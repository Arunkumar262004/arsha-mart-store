@php
    $cell = function ($value, string $type) {
        return match (true) {
            $value === null || $value === '' => '—',
            $value instanceof \Carbon\CarbonInterface => $value->format('d M Y, h:i A'),
            $type === 'money' => number_format((float) $value, 2),
            default => $value,
        };
    };
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28pt 28pt 36pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #0f172a; }
        h1 { font-size: 15pt; margin: 0; }
        h2 { font-size: 10pt; margin: 16pt 0 6pt; }
        .muted { color: #64748b; }
        .header { border-bottom: 2px solid #4f46e5; padding-bottom: 6pt; margin-bottom: 10pt; }
        .boxes { width: 100%; border-collapse: separate; border-spacing: 6pt 0; margin: 0 -6pt; }
        .box { border: 1px solid #e2e8f0; border-radius: 4pt; padding: 6pt 8pt; vertical-align: top; }
        .box .label { font-size: 7pt; text-transform: uppercase; color: #64748b; }
        .box .value { font-size: 11pt; font-weight: bold; margin-top: 2pt; }
        table.data { width: 100%; border-collapse: collapse; }
        /* Every cell is outlined so rows and columns are easy to follow on paper. */
        table.data { border: 1px solid #94a3b8; }
        table.data th { background: #eef2ff; text-align: left; font-size: 7pt; text-transform: uppercase; color: #334155; padding: 4pt; border: 1px solid #94a3b8; }
        table.data td { padding: 3pt 4pt; border: 1px solid #cbd5e1; }
        table.data tr { page-break-inside: avoid; }
        /* Grouped sheets: a shaded bill row, then its products indented under it. */
        table.data tr.group td { background: #e0e7ff; font-weight: bold; color: #1e1b4b; border-top: 1.5pt solid #6366f1; border-bottom: 1px solid #a5b4fc; }
        table.data tr.group td:first-child { border-left: 3pt solid #4f46e5; }
        table.data tr.item td { color: #334155; }
        table.data tr.item td:first-child { padding-left: 16pt; border-left: 3pt solid #c7d2fe; }
        .right { text-align: right; }
        .note { margin-top: 4pt; font-size: 7pt; color: #b45309; }
        .footer { position: fixed; bottom: -22pt; left: 0; right: 0; font-size: 7pt; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="footer">{{ config('app.name') }} · {{ $title }} · generated {{ now()->format('d M Y, h:i A') }}</div>

    <div class="header">
        <h1>{{ config('app.name') }} — {{ $title }}</h1>
        <div class="muted">
            {{ $period->from->format('d M Y') }} – {{ $period->to->format('d M Y') }}
            @foreach ($filters as [$label, $value])
                · {{ $label }}: {{ $value }}
            @endforeach
        </div>
    </div>

    <table class="boxes">
        <tr>
            @foreach ($summary as [$label, $value])
                <td class="box">
                    <div class="label">{{ $label }}</div>
                    <div class="value">{{ $value }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    @foreach ($sheets as $sheet)
        <h2>{{ $sheet['name'] }} <span class="muted">({{ $sheet['total_rows'] }})</span></h2>

        @if ($sheet['total_rows'] === 0)
            <p class="muted">Nothing in this period.</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($sheet['columns'] as [$label, $type])
                            <th class="{{ in_array($type, ['money', 'int']) ? 'right' : '' }}">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sheet['rows'] as $entry)
                        @foreach ($sheet['grouped'] ? [['group', $entry['row']], ...array_map(fn ($item) => ['item', $item], $entry['items'])] : [['', $entry]] as [$kind, $row])
                            <tr class="{{ $kind }}">
                                @foreach (array_values($row) as $i => $value)
                                    @php $type = $sheet['columns'][$i][1]; @endphp
                                    <td class="{{ in_array($type, ['money', 'int']) ? 'right' : '' }}">{{ $kind !== '' && $value === null ? '' : $cell($value, $type) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>

            @if ($sheet['total_rows'] > $maxRows)
                <p class="note">Showing the first {{ number_format($maxRows) }} of {{ number_format($sheet['total_rows']) }} {{ $sheet['grouped'] ? 'bills' : 'rows' }}. Download Excel for every row.</p>
            @endif
        @endif
    @endforeach
</body>
</html>
