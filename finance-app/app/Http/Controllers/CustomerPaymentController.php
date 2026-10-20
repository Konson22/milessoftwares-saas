<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Finance\Currencies;
use App\Finance\Money;
use App\Finance\Receivables;
use App\Models\Account;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money received from customers, and refunds.
 */
class CustomerPaymentController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public static function row(CustomerPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'kind' => $payment->kind,
            'source' => $payment->source_app,
            'invoiceId' => $payment->invoice_id,
            'invoiceNumber' => $payment->invoice_number,
            'method' => $payment->method->label(),
            'amountMinor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'currencyAmountMinor' => $payment->currency_amount_minor,
            'exchangeRate' => $payment->exchange_rate === null ? null : Currencies::normalize((string) $payment->exchange_rate),
            'fxMinor' => $payment->fx_minor,
            'receivedOn' => $payment->received_on->toDateString(),
            'account' => $payment->relationLoaded('account') ? $payment->account->label() : null,
            'journalId' => $payment->journal_id,
        ];
    }

    public function index(Request $request): Response
    {
        [$from, $to] = $this->period($request, CarbonImmutable::today()->startOfMonth());

        $payments = CustomerPayment::query()
            ->with('account')
            ->whereDate('received_on', '>=', $from)
            ->whereDate('received_on', '<=', $to)
            ->orderByDesc('received_on')
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get();

        return Inertia::render('customer-payments/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'payments' => $payments->map(fn (CustomerPayment $payment) => self::row($payment)),
            'totalMinor' => (int) $payments->sum(fn (CustomerPayment $payment) => $payment->signedAmount()),
        ]);
    }

    public function store(Request $request, Invoice $invoice, Receivables $receivables): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid', $this->openAccount(bank: true)],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount' => ['required', 'string', Money::rule()],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:255'],
            'rate' => ['nullable', 'string', Currencies::RATE_RULE],
        ]);

        $payment = $receivables->receive(
            $invoice,
            Account::query()->findOrFail((string) $data['account_id']),
            PaymentMethod::from($data['method']),
            $this->amountIn($data['amount'], $invoice->currency),
            CarbonImmutable::parse($data['received_on']),
            $data['memo'] ?? null,
            $this->user($request),
            ($data['rate'] ?? '') === '' ? null : Currencies::normalize($data['rate']),
        );

        return back()->with('success', "Receipt {$payment->number} recorded.");
    }
}
