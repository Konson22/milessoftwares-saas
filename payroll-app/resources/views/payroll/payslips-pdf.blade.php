{{--
    Payslips as a PDF (dompdf: tables, no flex/grid). One page per payslip.
    Amounts are payroll minor units (two decimals).
--}}
@php
    $money = fn (int $minor) => number_format($minor / 100, 2);
    $draft = $run->isDraft();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslips {{ $run->number }}</title>
    <style>
        @page { margin: 28mm 18mm 22mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #1f2937; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; padding: 0; }
        .org { font-size: 15pt; font-weight: bold; }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        .num { text-align: right; white-space: nowrap; }
        h1 { font-size: 11pt; margin: 0 0 2mm; text-transform: uppercase; letter-spacing: .5pt; color: #374151; }
        h2 { font-size: 10pt; margin: 0 0 1.5mm; }
        .lines td { padding: 1.6mm 0; border-bottom: .3pt solid #e5e7eb; }
        .lines tr.total td { border-bottom: 0; border-top: .8pt solid #9ca3af; font-weight: bold; }
        .col { width: 48%; }
        .gap { width: 4%; }
        .net { margin-top: 8mm; border-top: 1pt solid #111827; border-bottom: 1pt solid #111827; }
        .net td { padding: 3mm 0; font-size: 12pt; font-weight: bold; }
        .foot { margin-top: 6mm; font-size: 8.5pt; }
        .draft { position: fixed; top: 95mm; left: 0; right: 0; text-align: center; font-size: 64pt; font-weight: bold; color: #f3f4f6; transform: rotate(-30deg); z-index: -1; }
    </style>
</head>
<body>
@if ($draft)
    <div class="draft">DRAFT</div>
@endif

@foreach ($payslips as $payslip)
    @php
        $lines = collect($payslip['lines']);
        $earnings = $lines->where('kind', 'earning');
        $deductions = $lines->whereIn('kind', ['tax', 'social_security', 'deduction']);
        $employer = $lines->where('kind', 'employer');
    @endphp
    <div class="page">
        <table>
            <tr>
                <td>
                    <div class="org">{{ $organization }}</div>
                    <h1 style="margin-top: 2mm">Payslip</h1>
                    <div class="muted">{{ $run->label() }} · paid {{ $run->pay_date->format('j M Y') }}</div>
                    <div class="muted">Payroll {{ $run->number }}@if ($draft) (draft, not approved)@endif</div>
                </td>
                <td class="right">
                    <div style="font-weight: bold">{{ $payslip['name'] }}</div>
                    <div class="muted">Employee no. {{ $payslip['number'] }}</div>
                    @if ($payslip['jobTitle'])<div class="muted">{{ $payslip['jobTitle'] }}</div>@endif
                    @if ($payslip['branch'] || $payslip['department'])
                        <div class="muted">{{ collect([$payslip['branch'], $payslip['department']])->filter()->join(', ') }}</div>
                    @endif
                    @if ($payslip['daysPaid'] !== null)<div class="muted">{{ (float) $payslip['daysPaid'] }} days paid</div>@endif
                    @if ($payslip['hoursPaid'] !== null)<div class="muted">{{ (float) $payslip['hoursPaid'] }} hours paid</div>@endif
                </td>
            </tr>
        </table>

        <table style="margin-top: 9mm">
            <tr>
                <td class="col">
                    <h2>Earnings</h2>
                    <table class="lines">
                        @foreach ($earnings as $line)
                            <tr><td>{{ $line['name'] }}</td><td class="num">{{ $money($line['amount_minor']) }}</td></tr>
                        @endforeach
                        <tr class="total"><td>Total earnings</td><td class="num">{{ $money($payslip['earningsMinor']) }}</td></tr>
                    </table>
                </td>
                <td class="gap"></td>
                <td class="col">
                    <h2>Deductions</h2>
                    <table class="lines">
                        @forelse ($deductions as $line)
                            <tr><td>{{ $line['name'] }}</td><td class="num">{{ $money($line['amount_minor']) }}</td></tr>
                        @empty
                            <tr><td class="muted">None</td><td></td></tr>
                        @endforelse
                        <tr class="total"><td>Total deductions</td><td class="num">{{ $money($deductions->sum('amount_minor')) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="net">
            <tr><td>Net pay</td><td class="num">{{ $currency }} {{ $money($payslip['netMinor']) }}</td></tr>
        </table>

        <div class="foot muted">
            Taxable pay {{ $money($payslip['taxableMinor']) }}
            @foreach ($employer as $line) · {{ $line['name'] }} (paid by the employer) {{ $money($line['amount_minor']) }}@endforeach
        </div>
    </div>
@endforeach
</body>
</html>
