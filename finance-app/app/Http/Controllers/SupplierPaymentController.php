<?php

namespace App\Http\Controllers;

use App\Finance\Currencies;
use App\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier payments made. Suppliers are paid through payment requests
 * (PaymentRequestController): nothing here takes money out of a bank.
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
}
