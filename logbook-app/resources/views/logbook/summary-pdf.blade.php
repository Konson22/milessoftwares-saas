<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111827; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; vertical-align: top; padding: 4px 5px; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: bold; }
        tr.total td { font-weight: bold; border-top: 1px solid #9ca3af; }
        .footer { margin-top: 14px; font-size: 8px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="muted">{{ $organization }} · Miles Logbook</div>
    <h1>{{ $title }}</h1>
    <div class="muted">{{ implode(' · ', $applied) }}</div>

    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="{{ $loop->last && ($row[0] ?? null) === 'Total' ? 'total' : '' }}">
                    @foreach ($row as $cell)
                        <td>{{ $cell ?? '—' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}" class="muted">Nothing matches these filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">Printed {{ now($timezone)->format('Y-m-d H:i') }} ({{ $timezone }}) by {{ $printedBy }}. Only records the reader may see are counted.</div>
</body>
</html>
