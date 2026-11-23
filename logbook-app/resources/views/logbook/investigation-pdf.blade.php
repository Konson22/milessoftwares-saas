<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $investigation->reference }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #111827; line-height: 1.4; }
        h1 { font-size: 15px; margin: 2px 0 4px; }
        h2 { font-size: 11px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; vertical-align: top; padding: 4px 5px; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: bold; }
        .facts td { border: none; padding: 2px 8px 2px 0; }
        .facts td.k { color: #6b7280; width: 22%; }
        .note { margin: 0 0 8px; padding: 6px 8px; border: 1px solid #e5e7eb; }
        .note .meta { font-size: 8px; color: #6b7280; margin-bottom: 3px; }
        .pre { white-space: pre-line; }
        .tag { font-size: 8px; font-weight: bold; text-transform: uppercase; color: #374151; }
        .footer { margin-top: 18px; font-size: 8px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
    @php
        $fmt = fn ($at) => $at ? \Carbon\CarbonImmutable::parse($at)->setTimezone($timezone)->format('j M Y H:i') : '—';
        $kinds = ['observation' => 'Observations', 'hypothesis' => 'Hypotheses', 'finding' => 'Findings', 'conclusion' => 'Conclusions', 'follow_up' => 'Follow-up'];
        $restricted = collect($evidence)->where('restricted', true)->count();
    @endphp

    <div class="muted">{{ $organization }} · Miles Logbook · Investigation</div>
    <h1>{{ $investigation->reference }} · {{ $investigation->title }}</h1>

    <table class="facts">
        <tr><td class="k">Status</td><td>{{ $status }}</td></tr>
        <tr><td class="k">Logbook</td><td>{{ $investigation->logbook->name }}</td></tr>
        <tr><td class="k">Resource</td><td>{{ $investigation->resource?->label() ?? '—' }}</td></tr>
        <tr><td class="k">Incident time</td><td>{{ $fmt($investigation->occurred_at) }}</td></tr>
        <tr><td class="k">Period looked at</td><td>{{ $investigation->period_from?->toDateString() ?? '…' }} to {{ $investigation->period_to?->toDateString() ?? '…' }}</td></tr>
        <tr><td class="k">Investigator</td><td>{{ $investigation->investigator->name }}</td></tr>
        <tr><td class="k">Opened</td><td>{{ $fmt($investigation->created_at) }} by {{ $investigation->creator->name }}</td></tr>
        @if ($investigation->closed_at)
            <tr><td class="k">Closed</td><td>{{ $fmt($investigation->closed_at) }} by {{ $investigation->closer?->name }}</td></tr>
        @endif
    </table>

    @if ($investigation->description)
        <h2>Incident description</h2>
        <div class="pre">{{ $investigation->description }}</div>
    @endif

    <h2>Event timeline</h2>
    @if (count($timeline) === 0)
        <div class="muted">No dated evidence linked.</div>
    @else
        <table>
            <thead><tr><th style="width: 18%">When ({{ $timezone }})</th><th style="width: 14%">Clock</th><th style="width: 30%">Record</th><th>What</th></tr></thead>
            <tbody>
                @foreach ($timeline as $event)
                    <tr>
                        <td>{{ $fmt($event['at']) }}</td>
                        <td>{{ $event['basis'] === 'occurred' ? 'Happened' : 'Recorded' }}</td>
                        <td>{{ $event['title'] }}</td>
                        <td>{{ $event['detail'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Referenced evidence</h2>
    <table>
        <thead><tr><th style="width: 12%">Kind</th><th style="width: 38%">Record</th><th style="width: 20%">Added</th><th>Note</th></tr></thead>
        <tbody>
            @forelse (collect($evidence)->where('restricted', false) as $item)
                <tr>
                    <td>{{ ucfirst($item['kind']) }}@if ($item['role'] === 'corrective') <span class="tag">corrective</span>@endif</td>
                    <td>
                        {{ $item['label'] }}@if ($item['revision']) · revision {{ $item['revision'] }}@endif
                        @if ($item['removed']) <div class="muted">Removed {{ $fmt($item['removed']['at']) }} by {{ $item['removed']['by'] }}: {{ $item['removed']['reason'] }}</div>@endif
                    </td>
                    <td>{{ $fmt($item['addedAt']) }}<br><span class="muted">{{ $item['addedBy'] }}</span></td>
                    <td>{{ $item['note'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">None you can open.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($restricted > 0)
        <div class="muted" style="margin-top: 4px">{{ $restricted }} linked {{ $restricted === 1 ? 'record is' : 'records are' }} outside what {{ $printedBy }} may open and {{ $restricted === 1 ? 'is' : 'are' }} left out of this copy.</div>
    @endif

    @foreach ($kinds as $kind => $title)
        @php $written = $notes->where('kind', $kind); @endphp
        @if ($written->isNotEmpty())
            <h2>{{ $title }}</h2>
            @foreach ($written as $note)
                <div class="note">
                    <div class="meta">{{ $note->author->name }} · {{ $fmt($note->created_at) }}@if ($note->version > 1) · wording {{ $note->version }}@endif</div>
                    <div class="pre">{{ $note->body }}</div>
                </div>
            @endforeach
        @endif
    @endforeach

    <h2>Corrective tasks</h2>
    @php $corrective = collect($evidence)->where('role', 'corrective')->where('restricted', false)->whereNull('removed'); @endphp
    @if ($corrective->isEmpty())
        <div class="muted">None linked.</div>
    @else
        <ul>@foreach ($corrective as $task)<li>{{ $task['label'] }}</li>@endforeach</ul>
    @endif

    <h2>Closure</h2>
    @if ($investigation->status === 'closed')
        <div class="pre">{{ $investigation->closure_decision }}</div>
    @else
        <div class="muted">Not closed ({{ $status }}).</div>
    @endif

    <div class="footer">
        Everything above was written by the people named, or is a reference to an original record; nothing was worked out automatically.
        Generated {{ $printedAt->format('j M Y H:i') }} ({{ $timezone }}) by {{ $printedBy }}.
    </div>
</body>
</html>
