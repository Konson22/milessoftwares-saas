<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $entry->reference }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; vertical-align: top; padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
        th { width: 32%; color: #374151; font-weight: bold; }
        h2 { font-size: 12px; margin: 18px 0 4px; }
        .footer { margin-top: 18px; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="muted">{{ $organization }} · Tatook Logbook</div>
    <h1>{{ $entry->reference }} · {{ $entry->sheet->name }}</h1>
    <div class="muted">
        {{ $entry->logbook->name }} · template version {{ $entry->version->version }} ·
        status {{ $entry->status }}{{ $entry->review_required ? '' : ' (no review step)' }}
    </div>

    <table>
        <tr><th>Occurred at</th><td>{{ $entry->occurred_at->setTimezone($timezone)->format('Y-m-d H:i') }}</td></tr>
        <tr><th>Written by</th><td>{{ $entry->creator->name }}</td></tr>
        <tr><th>Submitted at</th><td>{{ $entry->submitted_at?->setTimezone($timezone)->format('Y-m-d H:i') }} (revision {{ $entry->revision }})</td></tr>
        @foreach ($answers as $answer)
            <tr>
                <th>{{ $answer['label'] }}</th>
                <td>{!! nl2br(e($answer['text'] ?? '—')) !!}</td>
            </tr>
        @endforeach
    </table>

    @if ($reviews->isNotEmpty())
        <h2>Reviews</h2>
        <table>
            @foreach ($reviews as $review)
                <tr>
                    <th>{{ $review->decided_at->setTimezone($timezone)->format('Y-m-d H:i') }} · revision {{ $review->revision }}</th>
                    <td>{{ $review->decision === 'reviewed' ? 'Reviewed' : 'Returned' }} by {{ $review->reviewer_name }}@if ($review->comment): {{ $review->comment }}@endif</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="footer">Printed {{ now($timezone)->format('Y-m-d H:i') }} ({{ $timezone }}) by {{ $printedBy }}. Every activity recorded. Every record accounted for.</div>
</body>
</html>
