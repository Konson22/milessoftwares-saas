<?php

namespace App\Http\Controllers;

use App\Actions\Invoices;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Shipment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing: invoices for delivered shipments, their payments and voids.
 */
class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $invoices = Invoice::query()
            ->when(in_array($status, Invoice::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->with('customer:id,name')
            ->latest('issued_on')
            ->latest()
            ->limit(300)
            ->get();
        $open = Invoice::query()->where('status', Invoice::ISSUED);

        return Inertia::render('invoices/index', [
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'customer' => $invoice->customer->name,
                'issuedOn' => $invoice->issued_on->toDateString(),
                'dueOn' => $invoice->due_on?->toDateString(),
                'status' => $invoice->status,
                'overdue' => $invoice->overdue(),
                'totalMinor' => $invoice->total_minor,
                'balanceMinor' => $invoice->balanceMinor(),
            ]),
            'filters' => ['status' => $status],
            'outstandingMinor' => (int) (clone $open)->sum('total_minor') - (int) (clone $open)->sum('paid_minor'),
            'toInvoice' => Shipment::query()->where('status', Shipment::DELIVERED)->whereNull('invoice_id')->count(),
            'canIssue' => $this->can($request, 'logistics.billing.manage'),
        ]);
    }

    /**
     * Choosing a customer, then which of their delivered shipments to bill.
     */
    public function create(Request $request): Response
    {
        $customerId = $request->string('customer')->toString();
        $waiting = Shipment::query()->where('status', Shipment::DELIVERED)->whereNull('invoice_id');

        return Inertia::render('invoices/create', [
            'customers' => Customer::query()
                ->whereIn('id', (clone $waiting)->select('customer_id'))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (Customer $customer) => ['id' => $customer->id, 'name' => $customer->name]),
            'customerId' => $customerId === '' ? null : $customerId,
            'shipments' => $customerId === '' ? [] : (clone $waiting)->where('customer_id', $customerId)->orderBy('delivered_at')->get()
                ->map(fn (Shipment $shipment) => [
                    'id' => $shipment->id,
                    'number' => $shipment->number,
                    'goods' => $shipment->goods,
                    'deliveryAddress' => $shipment->delivery_address,
                    'deliveredAt' => $shipment->delivered_at?->toIso8601String(),
                    'chargeMinor' => $shipment->charge_minor,
                ]),
        ]);
    }

    public function store(Request $request, Invoices $invoices): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'shipment_ids' => ['required', 'array', 'min:1', 'max:500'],
            'shipment_ids.*' => ['uuid'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $customer = Customer::query()->whereKey($validated['customer_id'])->first() ?? throw ValidationException::withMessages(['customer_id' => 'Choose a customer.']);

        $invoice = $invoices->issue(
            $customer,
            array_values($validated['shipment_ids']),
            isset($validated['due_on']) ? CarbonImmutable::parse($validated['due_on']) : null,
            $this->member($request),
        );

        return to_route('invoices.show', $invoice)->with('success', "Invoice {$invoice->number} issued to {$customer->name}.");
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $invoice->load(['customer', 'lines', 'payments.receiver:id,name', 'issuer:id,name', 'voider:id,name']);

        return Inertia::render('invoices/show', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'overdue' => $invoice->overdue(),
                'currency' => $invoice->currency,
                'customer' => [
                    'name' => $invoice->customer->name,
                    'phone' => $invoice->customer->phone,
                    'email' => $invoice->customer->email,
                    'address' => $invoice->customer->address,
                ],
                'issuedOn' => $invoice->issued_on->toDateString(),
                'dueOn' => $invoice->due_on?->toDateString(),
                'issuedBy' => $invoice->issuer->name,
                'totalMinor' => $invoice->total_minor,
                'paidMinor' => $invoice->paid_minor,
                'balanceMinor' => $invoice->balanceMinor(),
                'balance' => Money::toMajor($invoice->balanceMinor(), $invoice->currency),
                'voidedAt' => $invoice->voided_at?->toIso8601String(),
                'voidedBy' => $invoice->voider?->name,
                'voidReason' => $invoice->void_reason,
                'lines' => $invoice->lines->map(fn (InvoiceLine $line) => [
                    'id' => $line->id,
                    'shipmentId' => $line->shipment_id,
                    'description' => $line->description,
                    'amountMinor' => $line->amount_minor,
                ]),
                'payments' => $invoice->payments->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'number' => $payment->number,
                    'paidOn' => $payment->paid_on->toDateString(),
                    'method' => $payment->method->label(),
                    'reference' => $payment->reference,
                    'amountMinor' => $payment->amount_minor,
                    'receivedBy' => $payment->receiver->name,
                ]),
            ],
            'paymentMethods' => PaymentMethod::options(),
            'today' => today()->toDateString(),
            'can' => [
                'pay' => $this->can($request, 'logistics.billing.manage'),
                'void' => $this->can($request, 'logistics.billing.void'),
            ],
        ]);
    }

    public function pay(Request $request, Invoice $invoice, Invoices $invoices): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', Money::rule($invoice->currency)],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:128'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $payment = $invoices->pay(
            $invoice,
            Money::toMinor($validated['amount'], $invoice->currency),
            PaymentMethod::from($validated['method']),
            $validated['reference'] ?? null,
            CarbonImmutable::parse($validated['paid_on']),
            $this->member($request),
        );

        return back()->with('success', 'Payment '.$payment->number.' of '.Money::format($payment->amount_minor, $invoice->currency).' recorded.');
    }

    public function void(Request $request, Invoice $invoice, Invoices $invoices): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $invoices->void($invoice, $validated['reason'], $this->member($request));

        return back()->with('success', "Invoice {$invoice->number} voided: its shipments can be invoiced again.");
    }
}
