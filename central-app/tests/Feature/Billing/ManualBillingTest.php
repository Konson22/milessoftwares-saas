<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\PlanRequest;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

beforeEach(function () {
    config(['platform.admin.idle_minutes' => 100_000]);

    $importer = app(CatalogImporter::class);
    $importer->import(json_decode((string) file_get_contents(database_path('catalogs/platform.json')), true));
    $importer->import(json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true));
    $this->retail = Application::query()->where('code', 'retail')->sole();

    $this->plan = new Plan;
    $this->plan->forceFill([
        'code' => 'retail-standard', 'name' => 'Retail Standard', 'billing_interval' => 'monthly',
        'price_minor' => 2500, 'currency' => 'USD', 'trial_days' => 14, 'status' => 'active', 'is_public' => true,
    ])->save();
    $this->plan->applications()->attach($this->retail->id);

    [$this->organization, $this->owner] = billingOrganization();
    $this->billing = $this->staffWith(['billing_admin']);
});

/**
 * @return array{Organization, OrganizationMembership}
 */
function billingOrganization(): array
{
    $organization = Organization::factory()->create();
    $owner = OrganizationMembership::factory()->create(['organization_id' => $organization->id]);
    $organization->forceFill(['owner_membership_id' => $owner->id])->save();

    return [$organization, $owner];
}

function billingAppUrl(string $path): string
{
    return PlatformHosts::url(PlatformHosts::app(), $path);
}

function billingWorkAs(OrganizationMembership $membership): void
{
    test()->actingAs($membership->user)->post(billingAppUrl("organizations/{$membership->organization_id}/switch"));
}

function billingSubscribe(Organization $organization): Subscription
{
    return app(ManageEntitlements::class)->assignPlan($organization, test()->plan->load('applications'), null);
}

function billingInvoicesOf(Organization $organization)
{
    return app(TenantContext::class)->run($organization, fn () => Invoice::query()->with(['lines', 'payments'])->get());
}

function billingIssueFor(Organization $organization, Subscription $subscription): Invoice
{
    test()->actingAs(test()->billing->user)
        ->post(test()->adminUrl("/organizations/{$organization->id}/invoices"), [
            'subscription_id' => $subscription->id,
            'due_on' => now()->addDays(14)->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    return billingInvoicesOf($organization)->sortByDesc('issued_at')->firstOrFail()->withoutRelations();
}

it('invoices a subscription at its plan prices and shows the owner what is owed', function () {
    $invoice = billingIssueFor($this->organization, billingSubscribe($this->organization));
    $lines = billingInvoicesOf($this->organization)->sole()->lines;

    expect($invoice->number)->toMatch('/^INV-\d{4}-\d{6}$/')
        ->and($invoice->status)->toBe('open')
        ->and($invoice->currency)->toBe('USD')
        ->and($invoice->total_minor)->toBe(2500)
        ->and($lines)->toHaveCount(1)
        ->and($lines->first()->description)->toBe('Retail Standard');

    billingWorkAs($this->owner);

    $this->get(billingAppUrl('organization/billing'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('central/organizations/billing')
        ->has('invoices', 1)
        ->where('invoices.0.number', $invoice->number)
        ->where('summary.balances.0', ['currency' => 'USD', 'amountMinor' => 2500])
        ->where('summary.nextDue.id', $invoice->id));

    $this->get(billingAppUrl('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('current.billing.openInvoices', 1)
        ->has('current.billing.recentInvoices', 1));

    $this->get(billingAppUrl("organization/billing/invoices/{$invoice->id}"))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('central/organizations/invoice')
        ->where('invoice.totalMinor', 2500)
        ->has('invoice.lines', 1));
});

it('counts a reported payment only once staff confirm it', function () {
    $invoice = billingIssueFor($this->organization, billingSubscribe($this->organization));
    billingWorkAs($this->owner);

    $this->post(billingAppUrl("organization/billing/invoices/{$invoice->id}/payments"), [
        'method' => 'mobile_money', 'amount' => '10.00', 'reference' => 'MM-123', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasNoErrors();

    $payment = app(TenantContext::class)->run($this->organization, fn () => Payment::query()->sole());
    expect($payment->status)->toBe('reported')
        ->and($payment->amount_minor)->toBe(1000)
        ->and($invoice->refresh()->amount_paid_minor)->toBe(0);

    $this->actingAs($this->billing->user)
        ->post($this->adminUrl("/organizations/{$this->organization->id}/payments/{$payment->id}/confirm"))
        ->assertSessionHasNoErrors();

    expect($invoice->refresh()->amount_paid_minor)->toBe(1000)->and($invoice->status)->toBe('open');

    // Staff record the rest themselves: confirmed at once, the invoice is paid.
    $this->post($this->adminUrl("/organizations/{$this->organization->id}/invoices/{$invoice->id}/payments"), [
        'method' => 'bank_transfer', 'amount' => '15', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect($invoice->refresh()->status)->toBe('paid')
        ->and($invoice->paid_at)->not->toBeNull()
        ->and(AuditLog::query()->whereIn('action', ['invoice.issued', 'payment.reported', 'payment.confirmed', 'payment.recorded'])->count())->toBe(4);
});

it('refuses a payment larger than what is still owed', function () {
    $invoice = billingIssueFor($this->organization, billingSubscribe($this->organization));
    billingWorkAs($this->owner);

    $this->post(billingAppUrl("organization/billing/invoices/{$invoice->id}/payments"), [
        'method' => 'cash', 'amount' => '25.01', 'reference' => 'R1', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasErrors('amount');

    $this->post(billingAppUrl("organization/billing/invoices/{$invoice->id}/payments"), [
        'method' => 'cash', 'amount' => '0', 'reference' => 'R1', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasErrors('amount');

    expect(app(TenantContext::class)->run($this->organization, fn () => Payment::query()->count()))->toBe(0);
});

it('rejects a report with a reason the owner can see, and decides each report once', function () {
    $invoice = billingIssueFor($this->organization, billingSubscribe($this->organization));
    billingWorkAs($this->owner);
    $this->post(billingAppUrl("organization/billing/invoices/{$invoice->id}/payments"), [
        'method' => 'bank_transfer', 'amount' => '25', 'reference' => 'TX-9', 'paid_on' => today()->toDateString(),
    ]);
    $payment = app(TenantContext::class)->run($this->organization, fn () => Payment::query()->sole());

    $this->actingAs($this->billing->user)
        ->post($this->adminUrl("/organizations/{$this->organization->id}/payments/{$payment->id}/reject"), ['reason' => 'Not received'])
        ->assertSessionHasNoErrors();
    $this->post($this->adminUrl("/organizations/{$this->organization->id}/payments/{$payment->id}/confirm"))
        ->assertSessionHasErrors('payment');

    expect($payment->refresh()->status)->toBe('rejected')->and($invoice->refresh()->amount_paid_minor)->toBe(0);

    billingWorkAs($this->owner);
    $this->get(billingAppUrl('organization/billing'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('payments.0.status', 'rejected')
        ->where('payments.0.rejectionReason', 'Not received'));
});

it('voids an unpaid invoice and rejects reports still waiting on it', function () {
    $invoice = billingIssueFor($this->organization, billingSubscribe($this->organization));
    billingWorkAs($this->owner);
    $this->post(billingAppUrl("organization/billing/invoices/{$invoice->id}/payments"), [
        'method' => 'cash', 'amount' => '5', 'reference' => 'C1', 'paid_on' => today()->toDateString(),
    ]);

    $this->actingAs($this->billing->user)
        ->post($this->adminUrl("/organizations/{$this->organization->id}/invoices/{$invoice->id}/void"), ['reason' => 'Wrong plan'])
        ->assertSessionHasNoErrors();

    expect($invoice->refresh()->status)->toBe('void')
        ->and(app(TenantContext::class)->run($this->organization, fn () => Payment::query()->sole()->status))->toBe('rejected');
});

it('will not void an invoice something was paid against', function () {
    $invoice = billingIssueFor($this->organization, billingSubscribe($this->organization));

    $this->actingAs($this->billing->user)->post($this->adminUrl("/organizations/{$this->organization->id}/invoices/{$invoice->id}/payments"), [
        'method' => 'cash', 'amount' => '5', 'paid_on' => today()->toDateString(),
    ]);
    $this->post($this->adminUrl("/organizations/{$this->organization->id}/invoices/{$invoice->id}/void"), ['reason' => 'x'])
        ->assertSessionHasErrors('reason');

    expect($invoice->refresh()->status)->toBe('open');
});

it('issues a one-off invoice without a subscription', function () {
    $this->actingAs($this->billing->user)->post($this->adminUrl("/organizations/{$this->organization->id}/invoices"), [
        'description' => 'Onboarding and data import',
        'amount' => '150.50',
        'currency' => 'ssp',
        'due_on' => now()->addWeek()->toDateString(),
    ])->assertSessionHasNoErrors();

    $invoice = billingInvoicesOf($this->organization)->sole();
    expect($invoice->total_minor)->toBe(15050)->and($invoice->currency)->toBe('SSP')->and($invoice->subscription_id)->toBeNull();
});

it('keeps billing to the owner and to the organization it belongs to', function () {
    $invoice = billingIssueFor($this->organization, billingSubscribe($this->organization));
    $member = OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);
    [, $otherOwner] = billingOrganization();

    billingWorkAs($member);
    $this->get(billingAppUrl('organization/billing'))->assertForbidden();
    $this->get(billingAppUrl('organization/plans'))->assertForbidden();
    $this->post(billingAppUrl("organization/billing/invoices/{$invoice->id}/payments"), [
        'method' => 'cash', 'amount' => '5', 'reference' => 'X', 'paid_on' => today()->toDateString(),
    ])->assertForbidden();
    $this->get(billingAppUrl('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('current.billing', null));

    billingWorkAs($otherOwner);
    $this->get(billingAppUrl("organization/billing/invoices/{$invoice->id}"))->assertNotFound();
    $this->post(billingAppUrl("organization/billing/invoices/{$invoice->id}/payments"), [
        'method' => 'cash', 'amount' => '5', 'reference' => 'X', 'paid_on' => today()->toDateString(),
    ])->assertNotFound();

    // Staff acting on the wrong organization's URL find nothing either.
    $this->actingAs($this->billing->user)
        ->post($this->adminUrl("/organizations/{$otherOwner->organization_id}/invoices/{$invoice->id}/void"), ['reason' => 'x'])
        ->assertNotFound();
});

it('keeps billing actions to staff with billing.manage', function () {
    $invoice = billingIssueFor($this->organization, billingSubscribe($this->organization));
    $support = $this->staffWith(['support_agent']);

    $this->actingAs($support->user)->get($this->adminUrl('/billing'))->assertForbidden();
    $this->post($this->adminUrl("/organizations/{$this->organization->id}/invoices/{$invoice->id}/void"), ['reason' => 'x'])->assertForbidden();

    $this->actingAs($this->billing->user)->get($this->adminUrl('/billing'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/index'));
});

it('starts a plan the owner asked for once staff approve', function () {
    billingWorkAs($this->owner);

    $this->get(billingAppUrl('organization/plans'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('central/organizations/plans')
        ->has('plans', 1)
        ->where('plans.0.subscribed', false));

    $this->post(billingAppUrl('organization/plan-requests'), ['plan_id' => $this->plan->id, 'note' => 'Two tills'])->assertSessionHasNoErrors();
    $this->post(billingAppUrl('organization/plan-requests'), ['plan_id' => $this->plan->id])->assertSessionHasErrors('request');

    $request = app(TenantContext::class)->run($this->organization, fn () => PlanRequest::query()->sole());
    expect($request->status)->toBe('pending');

    $this->actingAs($this->billing->user)
        ->get($this->adminUrl('/billing'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('requests', 1)->where('requests.0.plan', 'Retail Standard'));
    $this->post($this->adminUrl("/organizations/{$this->organization->id}/plan-requests/{$request->id}/approve"), ['trial' => true])
        ->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe('approved')
        ->and(app(TenantContext::class)->run($this->organization, fn () => Subscription::query()->sole()->status))->toBe('trialing')
        ->and(app(TenantContext::class)->run($this->organization, fn () => Entitlement::query()->current()->where('application_id', $this->retail->id)->exists()))->toBeTrue();

    billingWorkAs($this->owner);
    $this->get(billingAppUrl('organization/plans'))->assertInertia(fn (AssertableInertia $page) => $page->where('plans.0.subscribed', true));
    $this->post(billingAppUrl('organization/plan-requests'), ['plan_id' => $this->plan->id])->assertSessionHasErrors('plan_id');
});

it('lets the owner withdraw a request, and staff decline one with a note', function () {
    billingWorkAs($this->owner);
    $this->post(billingAppUrl('organization/plan-requests'), ['plan_id' => $this->plan->id]);
    $first = app(TenantContext::class)->run($this->organization, fn () => PlanRequest::query()->sole());

    $this->post(billingAppUrl("organization/plan-requests/{$first->id}/withdraw"))->assertSessionHasNoErrors();
    expect($first->refresh()->status)->toBe('withdrawn');

    // Withdrawn requests no longer block asking again.
    $this->post(billingAppUrl('organization/plan-requests'), ['plan_id' => $this->plan->id])->assertSessionHasNoErrors();
    $second = app(TenantContext::class)->run($this->organization, fn () => PlanRequest::query()->where('status', 'pending')->sole());

    $this->actingAs($this->billing->user)
        ->post($this->adminUrl("/organizations/{$this->organization->id}/plan-requests/{$second->id}/decline"), ['note' => 'Call us first'])
        ->assertSessionHasNoErrors();

    expect($second->refresh()->status)->toBe('declined')
        ->and($second->decision_note)->toBe('Call us first')
        ->and(app(TenantContext::class)->run($this->organization, fn () => Subscription::query()->count()))->toBe(0);
});

it('ends a subscription when staff approve the owner\'s cancellation request', function () {
    $subscription = billingSubscribe($this->organization);
    billingWorkAs($this->owner);

    $this->post(billingAppUrl("organization/subscriptions/{$subscription->id}/cancellation"), ['note' => 'Closing the shop'])->assertSessionHasNoErrors();
    $this->get(billingAppUrl('organization/subscription'))->assertInertia(fn (AssertableInertia $page) => $page
        ->whereNot('subscriptions.0.cancellationRequestId', null)
        ->where('requests.0.type', 'cancel'));

    $request = app(TenantContext::class)->run($this->organization, fn () => PlanRequest::query()->sole());
    $this->actingAs($this->billing->user)
        ->post($this->adminUrl("/organizations/{$this->organization->id}/plan-requests/{$request->id}/approve"))
        ->assertSessionHasNoErrors();

    expect($subscription->refresh()->status)->toBe('cancelled')
        ->and(app(TenantContext::class)->run($this->organization, fn () => Entitlement::query()->current()->where('application_id', $this->retail->id)->exists()))->toBeFalse();
});

it('lets the owner set who invoices are addressed to', function () {
    billingWorkAs($this->owner);

    $this->put(billingAppUrl('organization/billing-details'), [
        'billing_email' => 'accounts@example.test',
        'billing_address' => 'Plot 4, Juba',
        'tax_id' => 'TIN-001',
    ])->assertSessionHasNoErrors();

    expect($this->organization->refresh()->billing_email)->toBe('accounts@example.test')
        ->and($this->organization->tax_id)->toBe('TIN-001');

    $this->get(billingAppUrl('organization/settings'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('billing.billing_email', 'accounts@example.test')
        ->has('timezones'));
});

it('shows the owner subscriptions, payments and the modules they can add', function () {
    // HR is announced; CRM is released and on a public plan.
    Application::query()->create(['code' => 'hr', 'name' => 'HR', 'category' => 'products', 'kind' => 'standalone', 'status' => 'planned']);
    $crm = Application::query()->create(['code' => 'crm', 'name' => 'CRM', 'category' => 'products', 'kind' => 'standalone', 'status' => 'available']);
    $crmPlan = new Plan;
    $crmPlan->forceFill([
        'code' => 'crm-standard', 'name' => 'CRM Standard', 'billing_interval' => 'monthly',
        'price_minor' => 1500, 'currency' => 'USD', 'trial_days' => 0, 'status' => 'active', 'is_public' => true,
    ])->save();
    $crmPlan->applications()->attach($crm->id);

    $invoice = billingIssueFor($this->organization, billingSubscribe($this->organization));
    billingWorkAs($this->owner);
    $this->post(billingAppUrl("organization/billing/invoices/{$invoice->id}/payments"), [
        'method' => 'mobile_money', 'amount' => '10.00', 'reference' => 'MM-123', 'paid_on' => today()->toDateString(),
    ])->assertSessionHasNoErrors();

    $this->get(billingAppUrl('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('current.billing.subscriptions', 1)
        ->where('current.billing.subscriptions.0.plans.0.name', 'Retail Standard')
        ->has('current.billing.recentPayments', 1)
        ->where('current.billing.recentPayments.0.amountMinor', 1000)
        ->where('current.billing.recentPayments.0.status', 'reported')
        ->where('current.billing.recentPayments.0.invoiceNumber', $invoice->number)
        ->where('current.billing.modules', fn ($modules) => collect($modules)->pluck('state', 'code')->only(['retail', 'crm', 'hr'])->sortKeys()->all()
            === ['crm' => 'available', 'hr' => 'coming_soon', 'retail' => 'installed'])
        // Recommendations: CRM and HR both pair with the installed Retail,
        // CRM more closely.
        ->where('current.billing.modules', fn ($modules) => collect($modules)->keyBy('code')->only(['crm', 'hr', 'retail'])
            ->map(fn ($module) => [$module['pairsWith'], $module['rank']])->sortKeys()->all()
            === ['crm' => ['Retail', 2], 'hr' => ['Retail', 3], 'retail' => [null, null]]));
});

it('keeps billing, payments and modules from members who do not own the organization', function () {
    $member = OrganizationMembership::factory()->create(['organization_id' => $this->organization->id]);
    billingWorkAs($member);

    $this->get(billingAppUrl('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('current.billing', null));
});
