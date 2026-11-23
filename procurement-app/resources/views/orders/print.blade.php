@php
    /** @var \App\Procurement\Models\PurchaseOrder $order */
    /** @var \App\Models\Workspace $settings */
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $order->number }} · {{ $order->supplier->name }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        body { font-family: "Segoe UI", system-ui, sans-serif; color: #111; background: #fff; margin: 0; padding: 32px; font-size: 13px; }
        h1 { font-size: 22px; margin: 0; }
        .muted { color: #555; }
        .row { display: flex; justify-content: space-between; gap: 32px; margin-top: 24px; }
        .box { flex: 1; border: 1px solid #ccc; padding: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f1f1f1; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .totals td { border: none; }
        .sign { margin-top: 48px; display: flex; gap: 48px; }
        .sign div { flex: 1; border-top: 1px solid #333; padding-top: 4px; }
        .first { margin-top: 0; }
        .right { text-align: right; }
        .terms { margin-top: 24px; }
        .notice { margin-top: 16px; padding: 8px; border: 1px solid #b45309; color: #b45309; }
        @media print { body { padding: 0; } .noprint { display: none; } }
    </style>
</head>
<body>
    <p class="noprint"><button type="button" id="print">Print or save as PDF</button></p>
    <script nonce="{{ Vite::cspNonce() }}">document.getElementById('print').addEventListener('click', () => window.print());</script>

    <div class="row first">
        <div>
            <h1>Purchase order {{ $order->number }}</h1>
            <div class="muted">{{ $settings->display_name }} · revision {{ $order->revision }}</div>
        </div>
        <div class="muted right">
            Date: {{ ($order->issued_at ?? $order->approved_at)?->format('j M Y') }}<br>
            Currency: {{ $order->currency }}
        </div>
    </div>

    @if (! in_array($order->status, ['issued', 'partially_received', 'received', 'closed'], true))
        <div class="notice">Approved, not yet issued to the supplier.</div>
    @endif

    <div class="row">
        <div class="box">
            <strong>Supplier</strong><br>
            {{ $order->supplier->name }}<br>
            @if ($order->supplier->contact_person) {{ $order->supplier->contact_person }}<br> @endif
            @if ($order->supplier->address) {!! nl2br(e($order->supplier->address)) !!}<br> @endif
            @if ($order->supplier->email) {{ $order->supplier->email }}<br> @endif
            @if ($order->supplier->phone) {{ $order->supplier->phone }}<br> @endif
            @if ($order->supplier->tax_number) Tax no. {{ $order->supplier->tax_number }} @endif
        </div>
        <div class="box">
            <strong>Deliver to</strong><br>
            {!! nl2br(e($order->delivery_address ?? '')) !!}<br>
            @if ($order->delivery_date) By {{ $order->delivery_date->format('j M Y') }}<br> @endif
            @if ($order->payment_terms) Payment terms: {{ $order->payment_terms }} @endif
        </div>
    </div>

    <table>
        <thead>
            <tr><th>#</th><th>Description</th><th class="num">Quantity</th><th>Unit</th><th class="num">Unit price</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($order->lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td>{{ $line->description }}@if ($line->kind === 'service') <span class="muted">(service)</span>@endif</td>
                    <td class="num">{{ rtrim(rtrim($line->quantity, '0'), '.') }}</td>
                    <td>{{ $line->unit }}</td>
                    <td class="num">{{ $money($line->unit_price_minor) }}</td>
                    <td class="num">{{ $money($line->amount_minor) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tbody class="totals">
            <tr><td colspan="5" class="num">Subtotal</td><td class="num">{{ $money($order->subtotal_minor) }}</td></tr>
            @if ($order->discount_minor) <tr><td colspan="5" class="num">Discount</td><td class="num">−{{ $money($order->discount_minor) }}</td></tr> @endif
            @if ($order->tax_minor) <tr><td colspan="5" class="num">Tax</td><td class="num">{{ $money($order->tax_minor) }}</td></tr> @endif
            @if ($order->delivery_minor) <tr><td colspan="5" class="num">Delivery</td><td class="num">{{ $money($order->delivery_minor) }}</td></tr> @endif
            <tr><td colspan="5" class="num"><strong>Total {{ $order->currency }}</strong></td><td class="num"><strong>{{ $money($order->total_minor) }}</strong></td></tr>
        </tbody>
    </table>

    @if ($order->notes)
        <p class="terms"><strong>Terms</strong><br>{!! nl2br(e($order->notes)) !!}</p>
    @endif

    <div class="sign">
        <div>Prepared by {{ $order->preparer->name }}</div>
        <div>Authorized signature</div>
        <div>Supplier acceptance</div>
    </div>
</body>
</html>
