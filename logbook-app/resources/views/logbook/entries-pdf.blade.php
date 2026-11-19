<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Log entries</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111827; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; vertical-align: top; padding: 4px 5px; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: bold; }
        .answers span { display: block; }
        .footer { margin-top: 14px; font-size: 8px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="muted">{{ $organization }} · Tatook Logbook</div>
    <h1>{{ $sheet ? $sheet->code.' · '.$sheet->name : 'Log entries' }}</h1>
    <div class="muted">
        {{ count($rows) }} {{ count($rows) === 1 ? 'entry' : 'entries' }}
        @if (! empty($filters['from']) || ! empty($filters['to'])) · {{ $filters['from'] ?? '…' }} to {{ $filters['to'] ?? '…' }} @endif
        @if (! empty($filters['status'])) · status {{ $filters['status'] }} @endif
        @if (! empty($filters['q'])) · matching “{{ $filters['q'] }}” @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 12%">Reference</th>
                <th style="width: 10%">Occurred</th>
                <th style="width: 14%">Sheet</th>
                <th style="width: 10%">By</th>
                <th style="width: 8%">Status</th>
                <th>Answers</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['entry']->reference }}</td>
                    <td>{{ $row['entry']->occurred_at->setTimezone($timezone)->format('Y-m-d H:i') }}</td>
                    <td>{{ $row['entry']->sheet->name }}</td>
                    <td>{{ $row['entry']->creator->name }}</td>
                    <td>{{ $row['entry']->status }}</td>
                    <td class="answers">
                        @foreach ($row['answers'] as $answer)
                            @if ($answer['text'] !== null)
                                <span><strong>{{ $answer['label'] }}:</strong> {{ $answer['text'] }}</span>
                            @endif
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">Printed {{ now($timezone)->format('Y-m-d H:i') }} ({{ $timezone }}) by {{ $printedBy }}.</div>
</body>
</html>
