<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\Payments;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Support\AccountStanding;
use App\Modules\Billing\Support\Money;
use App\Sites\Central\Http\Controllers\Dashboard\Concerns\ResolvesOwnedOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invoices from Miles Global Softwares and the payments made against them.
 * Payment happens outside the platform; the owner reports it here with its
 * reference and staff confirm it.
 */
class BillingController extends Controller
{
    use ResolvesOwnedOrganization;

    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request, AccountStanding $standing): Response
    {
        $organization = $this->ownedOrganization($request, $this->tenant);

        return Inertia::render('central/organizations/billing', [
            'organization' => [
                'displayName' => $organization->display_name,
                'billingEmail' => $organization->billing_email ?? $organization->contact_email,
            ],
            'summary' => $standing->summary(),
            'invoices' => Invoice::query()
                ->latest('issued_at')
                ->get()
                ->map(fn (Invoice $invoice) => $this->invoiceRow($invoice))
                ->all(),
            'payments' => Payment::query()
                ->with('invoice:id,number')
                ->latest('paid_on')
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (Payment $payment) => $this->paymentRow($payment))
                ->all(),
            'instructions' => config('platform.billing.payment_instructions'),
            'methods' => Payment::METHODS,
        ]);
    }

    public function invoice(Request $request, string $invoice): Response
    {
        $organization = $this->ownedOrganization($request, $this->tenant);
        // Tenant-scoped: another organization's invoice id 404s.
        $record = Invoice::query()->with(['lines', 'payments'])->findOrFail($invoice);

        return Inertia::render('central/organizations/invoice', [
            'invoice' => [
                ...$this->invoiceRow($record),
                'periodStart' => $record->period_start?->toDateString(),
                'periodEnd' => $record->period_end?->toDateString(),
                'notes' => $record->notes,
                'voidReason' => $record->void_reason,
                'lines' => $record->lines->map(fn (InvoiceLine $line) => [
                    'id' => $line->id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unitPriceMinor' => $line->unit_price_minor,
                    'amountMinor' => $line->amount_minor,
                ])->all(),
                'payments' => $record->payments->sortByDesc('paid_on')->values()->map(fn (Payment $payment) => $this->paymentRow($payment))->all(),
            ],
            'billTo' => [
                'name' => $organization->legal_name,
                'address' => $organization->billing_address ?? $organization->address,
                'email' => $organization->billing_email ?? $organization->contact_email,
                'taxId' => $organization->tax_id,
            ],
            'issuer' => [
                'name' => config('platform.billing.issuer'),
                'brand' => 'Miles Global Softwares',
                'email' => config('platform.billing.contact_email'),
            ],
            'instructions' => config('platform.billing.payment_instructions'),
            'methods' => Payment::METHODS,
        ]);
    }

    public function reportPayment(Request $request, string $invoice, Payments $payments): RedirectResponse
    {
        $organization = $this->ownedOrganization($request, $this->tenant);
        $record = Invoice::query()->findOrFail($invoice);

        $validated = $request->validate([
            'method' => ['required', Rule::in(Payment::METHODS)],
            'amount' => ['required', 'string', Money::RULE, 'not_regex:/^0+(\.0+)?$/'],
            'reference' => ['required', 'string', 'max:120'],
            'paid_on' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.$record->issued_at->subDays(60)->toDateString()],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $payments->report($organization, $record, $request->user(), [
            'method' => $validated['method'],
            'amount_minor' => Money::toMinor($validated['amount']),
            'reference' => $validated['reference'],
            'paid_on' => $validated['paid_on'],
            'note' => $validated['note'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment reported. We will confirm it once it reaches us.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->isOverdue() ? 'overdue' : $invoice->status,
            'currency' => $invoice->currency,
            'totalMinor' => $invoice->total_minor,
            'paidMinor' => $invoice->amount_paid_minor,
            'balanceMinor' => $invoice->balanceMinor(),
            'issuedOn' => $invoice->issued_at->toDateString(),
            'dueOn' => $invoice->due_on->toDateString(),
            'paidAt' => $invoice->paid_at?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRow(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'invoiceId' => $payment->invoice_id,
            'invoiceNumber' => $payment->relationLoaded('invoice') ? $payment->invoice->number : null,
            'status' => $payment->status,
            'method' => $payment->method,
            'amountMinor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'paidOn' => $payment->paid_on->toDateString(),
            'rejectionReason' => $payment->rejection_reason,
        ];
    }
}
