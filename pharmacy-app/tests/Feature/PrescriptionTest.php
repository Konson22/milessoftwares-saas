<?php

use App\Enums\PrescriptionStatus;
use App\Models\Batch;
use App\Models\Medicine;
use App\Models\Prescription;
use App\Models\Sale;
use App\Models\User;
use App\Models\Workspace;
use App\Pharmacy\Stock;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\SharedModuleKit\Envelope;

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->amox = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Amoxil', 'prescription_only' => true, 'sell_price_minor' => 1200]));
    $this->morphine = $this->asTenant('acme', fn () => Medicine::factory()->controlled()->create(['name' => 'Morphine', 'sell_price_minor' => 5000]));

    $keeper = User::factory()->create(['name' => 'Store Keeper']);
    $this->asTenant('acme', function () use ($keeper) {
        app(Stock::class)->add($this->amox, $this->juba, 'A-1', CarbonImmutable::parse('2027-06-30'), 30, 800, $keeper);
        app(Stock::class)->add($this->morphine, $this->juba, 'M-1', CarbonImmutable::parse('2027-03-31'), 10, 3000, $keeper);
    });
});

function pharmacist(string $branch): void
{
    test()->actingAsMember('acme', [
        'pharmacy.prescriptions.view', 'pharmacy.prescriptions.record', 'pharmacy.prescriptions.verify',
        'pharmacy.sales.create', 'pharmacy.sales.view', 'pharmacy.sales.void', 'pharmacy.controlled.view',
    ], branch: $branch);
}

function recordPrescription(array $overrides = []): Prescription
{
    test()->post(test()->workspaceUrl('acme', 'prescriptions'), $overrides + [
        'patient_name' => 'Mary Akol',
        'patient_age' => 42,
        'prescriber_name' => 'Dr. John Deng',
        'prescriber_registration' => 'MED-2231',
        'issued_on' => '2026-10-10',
        'items' => [
            ['medicine_id' => test()->amox->id, 'quantity' => 21, 'directions' => '1 capsule 3 times a day for 7 days'],
            ['medicine_id' => test()->morphine->id, 'quantity' => 2, 'directions' => '1 tablet at night if needed'],
        ],
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Prescription::query()->orderByDesc('number')->firstOrFail());
}

function dispense(Prescription $prescription, string $tendered = '400.00')
{
    return test()->post(test()->workspaceUrl('acme', "prescriptions/{$prescription->id}/dispense"), [
        'payment_method' => 'cash',
        'amount_tendered' => $tendered,
    ]);
}

it('records, verifies and dispenses a prescription as one sale', function () {
    pharmacist($this->juba);
    $prescription = recordPrescription();
    expect($prescription)->number->toBe('RX-00001')->status->toBe(PrescriptionStatus::Recorded);

    // Not before a pharmacist has verified it.
    dispense($prescription)->assertSessionHasErrors('status');

    $this->post($this->workspaceUrl('acme', "prescriptions/{$prescription->id}/verify"))->assertSessionHasNoErrors();
    dispense($prescription)->assertSessionHasNoErrors();

    $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
    expect($prescription->refresh())->status->toBe(PrescriptionStatus::Dispensed)->dispensed_sale_id->toBe($sale->id)
        ->and($sale)->total_minor->toBe(21 * 1200 + 2 * 5000)->customer_name->toBe('Mary Akol')
        ->and($this->asTenant('acme', fn () => Batch::query()->pluck('quantity_on_hand', 'batch_number')->all()))->toBe(['A-1' => 9, 'M-1' => 8]);

    // Finance sees an ordinary sale.
    expect(Envelope::fromArray($this->platform()->moduleEvents['finance'][0])->payload['total_minor'])->toBe(35200);

    // Never twice.
    dispense($prescription)->assertSessionHasErrors('status');
    expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(1);
});

it('still refuses prescription-only medicines at the counter', function () {
    pharmacist($this->juba);

    $this->post($this->workspaceUrl('acme', 'sales'), [
        'items' => [['medicine_id' => $this->morphine->id, 'quantity' => 1]],
        'payment_method' => 'cash',
        'amount_tendered' => '100.00',
    ])->assertSessionHasErrors('items.0.medicine_id');
});

it('refuses prescriptions older than 30 days', function () {
    pharmacist($this->juba);
    $prescription = recordPrescription(['issued_on' => '2026-09-01']);

    $this->post($this->workspaceUrl('acme', "prescriptions/{$prescription->id}/verify"))->assertSessionHasErrors('status');

    $fresh = recordPrescription();
    $this->post($this->workspaceUrl('acme', "prescriptions/{$fresh->id}/verify"));
    $this->travel(30)->days();
    dispense($fresh)->assertSessionHasErrors('status');
});

it('lets a pharmacist reject a prescription with a reason', function () {
    pharmacist($this->juba);
    $prescription = recordPrescription();

    $this->post($this->workspaceUrl('acme', "prescriptions/{$prescription->id}/reject"), ['reason' => 'Dose looks wrong'])->assertSessionHasNoErrors();

    expect($prescription->refresh())->status->toBe(PrescriptionStatus::Rejected)->rejection_reason->toBe('Dose looks wrong');
    dispense($prescription)->assertSessionHasErrors('status');
});

it('needs a pharmacist to verify', function () {
    $this->actingAsMember('acme', ['pharmacy.prescriptions.view', 'pharmacy.prescriptions.record', 'pharmacy.sales.create'], branch: $this->juba);
    $prescription = recordPrescription();

    $this->post($this->workspaceUrl('acme', "prescriptions/{$prescription->id}/verify"))->assertForbidden();
    expect($prescription->refresh()->status)->toBe(PrescriptionStatus::Recorded);
});

it('makes a prescription dispensable again when its sale is voided', function () {
    pharmacist($this->juba);
    $prescription = recordPrescription();
    $this->post($this->workspaceUrl('acme', "prescriptions/{$prescription->id}/verify"));
    dispense($prescription);
    $sale = $this->asTenant('acme', fn () => Sale::query()->sole());

    $this->post($this->workspaceUrl('acme', "sales/{$sale->id}/void"), ['reason' => 'Patient left'])->assertSessionHasNoErrors();

    expect($prescription->refresh())->status->toBe(PrescriptionStatus::Verified)->dispensed_sale_id->toBeNull();
    dispense($prescription)->assertSessionHasNoErrors();
});

it('keeps branches apart', function () {
    pharmacist($this->wau);
    $wauPrescription = recordPrescription();
    pharmacist($this->juba);

    $this->get($this->workspaceUrl('acme', "prescriptions/{$wauPrescription->id}"))->assertNotFound();
    $this->post($this->workspaceUrl('acme', "prescriptions/{$wauPrescription->id}/verify"))->assertNotFound();
});

it('keeps a controlled drugs register with a running balance and who it was for', function () {
    pharmacist($this->juba);
    $prescription = recordPrescription();
    $this->post($this->workspaceUrl('acme', "prescriptions/{$prescription->id}/verify"));
    dispense($prescription);

    $this->get($this->workspaceUrl('acme', 'controlled-register'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('controlled/index')
            ->where('selected', $this->morphine->id)
            ->has('entries', 2)
            // Newest first: the dispensing, then the opening receipt.
            ->where('entries.0.quantity', -2)
            ->where('entries.0.balance', 8)
            ->where('entries.0.prescription', 'RX-00001')
            ->where('entries.0.patient', 'Mary Akol')
            ->where('entries.0.prescriber', 'Dr. John Deng MED-2231')
            ->where('entries.0.document', 'S-000001')
            ->where('entries.1.quantity', 10)
            ->where('entries.1.balance', 10)
            ->where('entries.1.by', 'Store Keeper'));
});

it('opens the prescription pages', function () {
    pharmacist($this->juba);
    $prescription = recordPrescription();

    $this->get($this->workspaceUrl('acme', 'prescriptions'))->assertInertia(fn (Assert $page) => $page->component('prescriptions/index')->has('prescriptions', 1));
    $this->get($this->workspaceUrl('acme', 'prescriptions/create'))->assertInertia(fn (Assert $page) => $page->component('prescriptions/create'));
    $this->get($this->workspaceUrl('acme', "prescriptions/{$prescription->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('prescriptions/show')
            ->where('prescription.items.0.available', 30)
            ->where('can.verify', true)
            ->where('can.dispense', false));
});
