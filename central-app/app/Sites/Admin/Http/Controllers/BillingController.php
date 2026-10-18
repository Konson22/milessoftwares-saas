<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Enums\PlatformPermission;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Billing\Actions\Invoices;
use App\Modules\Billing\Actions\Payments;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Support\Money;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Actions\PlanRequests;
use App\Modules\Subscriptions\Models\PlanRequest;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invoices, payments and plan requests. Platform billing records, so like
 * subscriptions they need no support access grant. Every lookup by id runs
 * in the organization's tenant context: an id from another organization 404s.
 */
class BillingController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * What waits on staff across every organization.
     */
    public function index(Request $request): Response
    {
        $names = fn (iterable $organizationIds) => Organization::query()->whereKey(collect($organizationIds)->unique()->all())->pluck('display_name', 'id');

        $reported = Payment::acrossTenants()->with(['invoice' => fn ($query) => $query->withoutGlobalScopes(), 'reporter:id,name'])->where('status', 'reported')->oldest()->get();
        $requests = PlanRequest::acrossTenants()->with(['plan:id,name', 'requester:id,name'])->where('status', 'pending')->oldest()->get();
        $overdue = Invoice::acrossTenants()->where('status', 'open')->where('due_on', '<', today())->orderBy('due_on')->limit(50)->get();
        $organizations = $names([...$reported->pluck('organization_id'), ...$requests->pluck('organization_id'), ...$overdue->pluck('organization_id')]);

        return Inertia::render('admin/billing/index', [
            'payments' => $reported->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'organizationId' => $payment->organization_id,
                'organization' => $organizations[$payment->organization_id] ?? null,
                'invoiceNumber' => $payment->invoice->number,
                'method' => $payment->method,
                'amountMinor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'reference' => $payment->reference,
                'paidOn' => $payment->paid_on->toDateString(),
                'note' => $payment->note,
                'reportedBy' => $payment->reporter?->name,
            ])->all(),
            'requests' => $requests->map(fn (PlanRequest $planRequest) => [
                'id' => $planRequest->id,
                'organizationId' => $planRequest->organization_id,
                'organization' => $organizations[$planRequest->organization_id] ?? null,
                'type' => $planRequest->type,
                'plan' => $planRequest->plan?->name,
                'note' => $planRequest->note,
                'requestedBy' => $planRequest->requester?->name,
                'requestedAt' => $planRequest->created_at?->toDateString(),
            ])->all(),
            'overdue' => $overdue->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'organizationId' => $invoice->organization_id,
                'organization' => $organizations[$invoice->organization_id] ?? null,
                'number' => $invoice->number,
                'balanceMinor' => $invoice->balanceMinor(),
                'currency' => $invoice->currency,
                'dueOn' => $invoice->due_on->toDateString(),
            ])->all(),
            'can' => [
                'decideRequests' => $this->staff($request)->hasPermission(PlatformPermission::EntitlementsManage),
            ],
        ]);
    }

    public function issueInvoice(Request $request, Organization $organization, Invoices $invoices): RedirectResponse
    {
        $validated = $request->validate([
            'subscription_id' => ['nullable', 'uuid'],
            'description' => ['required_without:subscription_id', 'nullable', 'string', 'max:255'],
            'amount' => ['required_without:subscription_id', 'nullable', 'string', Money::RULE],
            'currency' => ['required_without:subscription_id', 'nullable', 'string', 'size:3', 'alpha'],
            'due_on' => ['required', 'date', 'after_or_equal:today'],
            'period_start' => ['nullable', 'date', 'required_with:period_end'],
            'period_end' => ['nullable', 'date', 'required_with:period_start', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $staff = $this->staff($request);
        $dueOn = CarbonImmutable::parse($validated['due_on']);
        $periodStart = isset($validated['period_start']) ? CarbonImmutable::parse($validated['period_start']) : null;
        $periodEnd = isset($validated['period_end']) ? CarbonImmutable::parse($validated['period_end']) : null;

        if (isset($validated['subscription_id'])) {
            $subscription = $this->tenant->run($organization, fn () => Subscription::query()->findOrFail($request->string('subscription_id')->toString()));
            $invoice = $invoices->issueForSubscription($organization, $subscription, $staff, $dueOn, $periodStart, $periodEnd, $validated['notes'] ?? null);
        } else {
            $invoice = $invoices->issue($organization, $staff, [[
                'description' => (string) $validated['description'],
                'quantity' => 1,
                'unit_price_minor' => Money::toMinor((string) $validated['amount']),
            ]], (string) $validated['currency'], $dueOn, null, $periodStart, $periodEnd, $validated['notes'] ?? null);
        }

        return $this->done(__('Invoice :number issued.', ['number' => $invoice->number]));
    }

    public function voidInvoice(Request $request, Organization $organization, string $invoice, Invoices $invoices): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $record = $this->tenant->run($organization, fn () => Invoice::query()->findOrFail($invoice));

        $invoices->void($organization, $record, $this->staff($request), $validated['reason']);

        return $this->done(__('Invoice voided.'));
    }

    public function recordPayment(Request $request, Organization $organization, string $invoice, Payments $payments): RedirectResponse
    {
        $validated = $request->validate([
            'method' => ['required', Rule::in(Payment::METHODS)],
            'amount' => ['required', 'string', Money::RULE, 'not_regex:/^0+(\.0+)?$/'],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $record = $this->tenant->run($organization, fn () => Invoice::query()->findOrFail($invoice));

        $payments->record($organization, $record, $this->staff($request), [
            'method' => $validated['method'],
            'amount_minor' => Money::toMinor($validated['amount']),
            'reference' => $validated['reference'] ?? null,
            'paid_on' => $validated['paid_on'],
            'note' => $validated['note'] ?? null,
        ]);

        return $this->done(__('Payment recorded.'));
    }

    public function confirmPayment(Request $request, Organization $organization, string $payment, Payments $payments): RedirectResponse
    {
        $record = $this->tenant->run($organization, fn () => Payment::query()->with('invoice')->findOrFail($payment));

        $payments->confirm($organization, $record, $this->staff($request));

        return $this->done(__('Payment confirmed.'));
    }

    public function rejectPayment(Request $request, Organization $organization, string $payment, Payments $payments): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $record = $this->tenant->run($organization, fn () => Payment::query()->with('invoice')->findOrFail($payment));

        $payments->reject($organization, $record, $this->staff($request), $validated['reason']);

        return $this->done(__('Payment rejected.'));
    }

    public function approveRequest(Request $request, Organization $organization, string $planRequest, PlanRequests $requests): RedirectResponse
    {
        $validated = $request->validate([
            'trial' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $record = $this->tenant->run($organization, fn () => PlanRequest::query()->findOrFail($planRequest));

        $requests->approve($organization, $record, $this->staff($request), (bool) ($validated['trial'] ?? false), $validated['note'] ?? null);

        return $this->done(__('Request approved.'));
    }

    public function declineRequest(Request $request, Organization $organization, string $planRequest, PlanRequests $requests): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $record = $this->tenant->run($organization, fn () => PlanRequest::query()->findOrFail($planRequest));

        $requests->decline($organization, $record, $this->staff($request), $validated['note']);

        return $this->done(__('Request declined.'));
    }

    private function staff(Request $request): PlatformStaff
    {
        $staff = $request->attributes->get('staff');
        abort_unless($staff instanceof PlatformStaff, 403);

        return $staff;
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
