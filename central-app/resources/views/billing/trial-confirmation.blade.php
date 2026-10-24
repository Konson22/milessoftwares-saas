<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $number }}</title>
    <style>
        /* dompdf: tables for layout, the logo's navy, royal blue and teal. */
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1e293b; line-height: 1.45; }
        .band { height: 8px; background: #0058f5; }
        .band span { display: block; height: 8px; width: 35%; background: #03d5cd; margin-left: 65%; }
        .page { padding: 34px 44px 30px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .logo { height: 54px; }
        .title { text-align: right; }
        .title h1 { margin: 0; font-size: 24pt; letter-spacing: 2px; color: #0a2260; }
        .title .kind { margin-top: 2px; font-size: 10pt; font-weight: bold; color: #0058f5; text-transform: uppercase; letter-spacing: 1px; }
        .muted { color: #64748b; }
        .label { font-size: 7.5pt; font-weight: bold; color: #0058f5; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .parties { margin-top: 26px; }
        .parties td { width: 50%; }
        .parties td:first-child { padding-right: 28px; }
        .strong { font-weight: bold; color: #0a2260; }
        .meta { margin-top: 22px; background: #f3f8ff; border: 1px solid #dfe7f3; }
        .meta td { padding: 10px 14px; width: 25%; border-right: 1px solid #dfe7f3; }
        .meta td:last-child { border-right: 0; }
        .meta .value { font-weight: bold; color: #0a2260; }
        .paid { color: #006e69; }
        .lines { margin-top: 24px; }
        .lines th { background: #0a2260; color: #ffffff; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 1px; padding: 9px 12px; }
        .lines th.amount, .lines td.amount { text-align: right; white-space: nowrap; }
        .lines .col-desc { width: 46%; }
        .lines .col-period { width: 30%; }
        .lines .col-qty { width: 8%; }
        .lines .col-amount { width: 16%; }
        .lines td { padding: 11px 12px; border-bottom: 1px solid #e2e8f0; }
        .totals { margin-top: 8px; }
        .totals td { padding: 5px 12px; }
        .totals .key { text-align: right; width: 80%; color: #475569; }
        .totals .val { text-align: right; width: 20%; white-space: nowrap; }
        .totals .grand td { border-top: 2px solid #0a2260; padding-top: 9px; font-size: 12pt; font-weight: bold; color: #0a2260; }
        .note { margin-top: 26px; padding: 14px 16px; border-left: 4px solid #03d5cd; background: #f0fdfc; }
        .note p { margin: 0 0 4px; }
        .plans { margin-top: 22px; }
        .plans th { text-align: left; font-size: 8pt; color: #64748b; text-transform: uppercase; letter-spacing: 1px; padding: 6px 12px; border-bottom: 1px solid #dfe7f3; }
        .plans td { padding: 8px 12px; border-bottom: 1px solid #eef2f7; }
        .plans td.amount, .plans th.amount { text-align: right; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 14px 44px 18px; border-top: 1px solid #dfe7f3; font-size: 8pt; color: #64748b; text-align: center; }
        .footer .brand { color: #0a2260; font-weight: bold; }
    </style>
</head>
<body>
    <div class="band"><span></span></div>

    <div class="page">
        <table>
            <tr>
                <td>
                    @if ($logo)
                        <img class="logo" src="{{ $logo }}" alt="Miles Global Softwares">
                    @else
                        <span class="strong">{{ $from['name'] }}</span>
                    @endif
                </td>
                <td class="title">
                    <h1>INVOICE</h1>
                    <div class="kind">Free trial confirmation</div>
                    <div class="muted">No. {{ $number }}</div>
                </td>
            </tr>
        </table>

        <table class="parties">
            <tr>
                <td>
                    <div class="label">From</div>
                    <div class="strong">{{ $from['name'] }}</div>
                    <div>{{ $from['issuer'] }}</div>
                    <div>{{ $from['address'] }}</div>
                    <div>{{ $from['phone'] }}@if ($from['email']) · {{ $from['email'] }}@endif</div>
                    <div>{{ $from['website'] }}</div>
                </td>
                <td>
                    <div class="label">Bill to</div>
                    @foreach ($billTo as $i => $line)
                        <div @class(['strong' => $i === 0])>{{ $line }}</div>
                    @endforeach
                </td>
            </tr>
        </table>

        <table class="meta">
            <tr>
                <td>
                    <div class="label">Issue date</div>
                    <div class="value">{{ $issuedOn }}</div>
                </td>
                <td>
                    <div class="label">Trial starts</div>
                    <div class="value">{{ $trialStart }}</div>
                </td>
                <td>
                    <div class="label">Trial ends</div>
                    <div class="value">{{ $trialEnd }}</div>
                </td>
                <td>
                    <div class="label">Amount due</div>
                    <div class="value paid">{{ $total }}</div>
                </td>
            </tr>
        </table>

        <table class="lines">
            <thead>
                <tr>
                    <th class="col-desc">Description</th>
                    <th class="col-period">Period</th>
                    <th class="col-qty">Qty</th>
                    <th class="amount col-amount">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td class="strong">{{ $line['description'] }}</td>
                        <td class="muted">{{ $line['detail'] }}</td>
                        <td>1</td>
                        <td class="amount">{{ $line['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td class="key">Subtotal</td>
                <td class="val">{{ $total }}</td>
            </tr>
            <tr class="grand">
                <td class="key">Total due</td>
                <td class="val">{{ $total }}</td>
            </tr>
        </table>

        <div class="note">
            <p class="strong">Your {{ $days }}-day {{ $application }} trial is free.</p>
            <p>Nothing is charged automatically. To keep using {{ $application }} after {{ $trialEnd }}, choose a plan from your dashboard; everything you entered during the trial stays in place.</p>
        </div>

        @if ($plans !== [])
            <table class="plans">
                <thead>
                    <tr>
                        <th>After your trial</th>
                        <th>Billed</th>
                        <th class="amount">Price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plans as $plan)
                        <tr>
                            <td class="strong">{{ $plan['name'] }}</td>
                            <td class="muted">{{ $plan['interval'] === 'custom' ? 'By agreement' : ucfirst($plan['interval']) }}</td>
                            <td class="amount">{{ $plan['price'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="footer">
        <span class="brand">{{ $from['name'] }}</span> · {{ $from['address'] }} · {{ $from['phone'] }} · {{ $from['website'] }}<br>
        Thank you for choosing us. Questions about this invoice? Reply to this email or message us on WhatsApp.
    </div>
</body>
</html>
