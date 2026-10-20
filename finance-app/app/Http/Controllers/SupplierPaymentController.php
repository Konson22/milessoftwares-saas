<?php

namespace App\Http\Controllers;

use App\Finance\Currencies;
use App\Finance\Money;
use App\Finance\Payables;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paying suppliers, from a bank or cash account, against their bills.
 */
class SupplierPaymentController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public static function row(SupplierPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'supplier' => $payment->relationLoaded('supplier') ? $payment->supplier->name : null,
            'supplierId' => $payment->supplier_id,
            'paidOn' => $payment->paid_on->toDateString(),
            'amountMinor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'currencyAmountMinor' => $payment->currency_amount_minor,
            'exchangeRate' => $payment->exchange_rate === null ? null : Currencies::normalize((string) $payment->exchange_rate),
            'fxMinor' => $payment->fx_minor,
            'account' => $payment->relationLoaded('account') ? $payment->account->label() : null,
            'reference' => $payment->reference,
            'journalId' => $payment->journal_id,
        ];
    }

    public function index(Request $request): Response
    {
        [$from, $to] = $this->period($request, CarbonImmutable::today()->startOfMonth());

        $payments = SupplierPayment::query()
            ->with(['supplier', 'account'])
            ->whereDate('paid_on', '>=', $from)
            ->whereDate('paid_on', '<=', $to)
            ->orderByDesc('paid_on')
            ->orderByDesc('number')
            ->get();

        return Inertia::render('supplier-payments/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'payments' => $payments->map(fn (SupplierPayment $payment) => self::row($payment)),
            'totalMinor' => (int) $payments->sum('amount_minor'),
        ]);
    }

    public function create(Request $request): Response
    {
        $supplier = is_string($request->query('supplier')) ? Supplier::query()->find($request->query('supplier')) : null;

        return Inertia::render('supplier-payments/create', [
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'supplierId' => $supplier?->id,
            'banks' => $this->accountOptions(bank: true),
            'bills' => $supplier === null ? [] : $supplier->bills()
                ->whereColumn('paid_minor', '<', 'total_minor')
                ->orderBy('bill_date')
                ->get()
                ->map(fn (Bill $bill) => BillController::row($bill)),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(Request $request, Payables $payables): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid', $this->ours('suppliers')],
            'account_id' => ['required', 'uuid', $this->openAccount(bank: true)],
            'amount' => ['required', 'string', Money::rule()],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:64'],
            'memo' => ['nullable', 'string', 'max:255'],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*' => ['nullable', 'string', Money::rule()],
            'rate' => ['nullable', 'string', Currencies::RATE_RULE],
        ]);

        $typed = [];
        foreach ($data['allocations'] ?? [] as $billId => $amount) {
            if (! Str::isUuid((string) $billId)) {
                throw ValidationException::withMessages(['allocations' => 'Unknown bill.']);
            }
            if (is_string($amount) && $amount !== '') {
                $typed[(string) $billId] = $amount;
            }
        }

        // Amounts are in the payment's currency: the bank's, or else the bills'.
        $from = Account::query()->findOrFail((string) $data['account_id']);
        $currency = $from->currency ?? Bill::query()->whereIn('id', array_keys($typed))->whereNotNull('currency')->value('currency');
        $allocations = [];
        foreach ($typed as $billId => $amount) {
            $allocations[$billId] = $this->amountIn($amount, $currency, "allocations.{$billId}");
        }

        $supplier = Supplier::query()->findOrFail((string) $data['supplier_id']);
        $payment = $payables->pay(
            $supplier,
            $from,
            $this->amountIn($data['amount'], $currency),
            CarbonImmutable::parse($data['paid_on']),
            $allocations,
            $data['reference'] ?? null,
            $data['memo'] ?? null,
            $this->user($request),
            ($data['rate'] ?? '') === '' ? null : Currencies::normalize($data['rate']),
        );

        return redirect()->route('suppliers.show', $supplier)->with('success', "Payment {$payment->number} recorded.");
    }
}
