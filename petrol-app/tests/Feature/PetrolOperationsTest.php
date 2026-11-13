<?php

use App\Models\AppSubmission;
use App\Models\Attendant;
use App\Models\AttendantHandover;
use App\Models\AttendantStation;
use App\Models\AuditLog;
use App\Models\BankDeposit;
use App\Models\CashCount;
use App\Models\CashMovement;
use App\Models\CashTransfer;
use App\Models\CreditEntry;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSchedule;
use App\Models\FuelDraw;
use App\Models\FuelProduct;
use App\Models\FuelReceipt;
use App\Models\FuelSale;
use App\Models\FuelTransfer;
use App\Models\Nozzle;
use App\Models\NozzleEvent;
use App\Models\PaymentMethod;
use App\Models\Pump;
use App\Models\PurchaseOrder;
use App\Models\Safe;
use App\Models\ServiceBay;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftBreak;
use App\Models\ShiftException;
use App\Models\Station;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\StoreBalance;
use App\Models\StoreMovement;
use App\Models\StoreProduct;
use App\Models\StoreTransfer;
use App\Models\Supplier;
use App\Models\Tank;
use App\Models\TankChart;
use App\Models\TankMeasurement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Models\Workspace;
use App\Petrol\Cashbook;
use App\Petrol\Credit;
use App\Petrol\Currencies;
use App\Petrol\Custody;
use App\Petrol\Permissions;
use App\Petrol\Reconciliation;
use App\Petrol\ShiftExceptions;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\SharedModuleKit\Envelope;

/*
| Petrol station operations: stock, a sale, a shift closing, credit,
| a delivery and an offline replay. Finance is told through the outbox.
*/

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    config(['platform-client.mobile.client_id' => 'mobile-client']);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00', 'UTC'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create([
        'display_name' => 'Acme',
        'currency' => 'SSP',
        'timezone' => 'Africa/Juba',
    ]));
    $this->platform()->moduleEvents = [];
});

/**
 * @return list<string>
 */
function petrolPermissions(): array
{
    return array_values(array_filter(
        (new ReflectionClass(Permissions::class))->getConstants(),
        fn (mixed $value): bool => is_string($value) && substr_count($value, '.') === 2,
    ));
}

function at(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path);
}

/**
 * @return list<string>
 */
function financeEvents(): array
{
    return array_map(
        fn (array $event) => Envelope::fromArray($event)->eventType,
        test()->platform()->moduleEvents['finance'] ?? [],
    );
}

function manager(?User $user = null): User
{
    $member = test()->actingAsMember('acme', petrolPermissions(), branch: test()->juba, user: $user);
    assert($member instanceof User);

    return $member;
}

/**
 * A station ready to sell: PMS at 10.00, 5,000 L in tank T1, nozzle N1, one attendant, an open shift.
 *
 * @return array{tank: string, nozzle: string, attendant: string, shift: string, cash: string, credit: string}
 */
function forecourt(): array
{
    manager();
    test()->post(at('settings/station'), [
        'code' => 'JUBA',
        'collection_mode' => 'both',
        'low_stock_percent' => 15,
        'active' => 1,
    ])->assertSessionHasNoErrors();

    test()->post(at('products'), ['name' => 'Petrol', 'code' => 'PMS', 'active' => 1])->assertSessionHasNoErrors();
    $product = test()->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    test()->post(at('settings/prices'), ['fuel_product_id' => $product->id, 'selling_price' => '10.00'])->assertSessionHasNoErrors();

    test()->post(at('tanks'), [
        'fuel_product_id' => $product->id,
        'name' => 'Tank 1',
        'code' => 'T1',
        'capacity' => '20000',
        'status' => 'active',
    ])->assertSessionHasNoErrors();
    $tank = test()->asTenant('acme', fn () => Tank::query()->where('code', 'T1')->sole());
    test()->post(at("tanks/{$tank->id}/opening"), ['litres' => '5000', 'cost' => '8.00'])->assertSessionHasNoErrors();

    test()->post(at('pumps'), ['name' => 'Pump 1', 'code' => 'P1', 'status' => 'active'])->assertSessionHasNoErrors();
    $pump = test()->asTenant('acme', fn () => Pump::query()->where('code', 'P1')->sole());
    test()->post(at("pumps/{$pump->id}/nozzles"), [
        'fuel_product_id' => $product->id,
        'name' => 'Nozzle 1',
        'code' => 'N1',
    ])->assertSessionHasNoErrors();
    $nozzle = test()->asTenant('acme', fn () => Nozzle::query()->where('code', 'N1')->sole());
    test()->post(at("nozzles/{$nozzle->id}/connect"), ['tank_id' => $tank->id])->assertSessionHasNoErrors();

    test()->post(at('attendants'), [
        'name' => 'Amina',
        'employee_code' => 'A1',
        'active' => 1,
        'at_station' => 1,
    ])->assertSessionHasNoErrors();
    $attendant = test()->asTenant('acme', fn () => Attendant::query()->where('employee_code', 'A1')->sole());

    test()->post(at('shifts'), [
        'opening_float' => '100.00',
        'openings' => [['nozzle_id' => $nozzle->id, 'reading' => '1000']],
    ])->assertRedirect();
    $shift = test()->asTenant('acme', fn () => Shift::query()->where('status', Shift::OPEN)->sole());
    test()->post(at("shifts/{$shift->id}/assignments"), [
        'attendant_id' => $attendant->id,
        'nozzle_id' => $nozzle->id,
    ])->assertSessionHasNoErrors();

    $methods = test()->asTenant('acme', fn () => PaymentMethod::query()->where('branch_id', test()->juba)->pluck('id', 'code'));

    return [
        'tank' => $tank->id,
        'nozzle' => $nozzle->id,
        'attendant' => $attendant->id,
        'shift' => $shift->id,
        'cash' => (string) $methods['CASH'],
        'credit' => (string) $methods['CREDIT'],
    ];
}

it('sells fuel once, reduces the tank, and does not post the same sale again', function () {
    $yard = forecourt();
    $payload = [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '100',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'idempotency_key' => (string) Str::uuid(),
        'payments' => [['method_id' => $yard['cash'], 'amount' => '1000.00']],
    ];

    $this->post(at('sales'), $payload)->assertRedirect();
    $this->post(at('sales'), $payload)->assertRedirect();

    $sale = $this->asTenant('acme', fn () => FuelSale::query()->sole());
    expect($sale->total_minor)->toBe(100000)
        ->and($sale->cost_minor)->toBe(80000)
        ->and($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->quantity_milli))->toBe(4_900_000);

    expect(financeEvents())->toContain('finance.stock.adjusted', 'finance.invoice.issued', 'finance.payment.received')
        ->and(array_count_values(financeEvents())['finance.invoice.issued'])->toBe(1)
        ->and(array_count_values(financeEvents())['finance.payment.received'])->toBe(1);
});

it('refuses credit past the limit and accepts credit inside it', function () {
    $yard = forecourt();
    $this->post(at('customers'), [
        'name' => 'Fleet Co',
        'kind' => 'fleet',
        'credit_limit' => '500.00',
    ])->assertSessionHasNoErrors();
    $customer = $this->asTenant('acme', fn () => Customer::query()->sole());

    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '60',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['credit'], 'amount' => '600.00', 'customer_id' => $customer->id]],
    ])->assertSessionHasErrors('payments');

    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '40',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['credit'], 'amount' => '400.00', 'customer_id' => $customer->id]],
    ])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(1);
});

it('restores stock when a sale is voided', function () {
    $yard = forecourt();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '100',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '1000.00']],
    ])->assertRedirect();
    $sale = $this->asTenant('acme', fn () => FuelSale::query()->sole());

    $this->post(at("sales/{$sale->id}/void"), ['reason' => 'Wrong nozzle'])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->quantity_milli))->toBe(5_000_000)
        ->and($sale->fresh()->status)->toBe(FuelSale::VOIDED)
        ->and(financeEvents())->toContain('finance.invoice.voided');
});

it('keeps dispensed litres honest and stops the submitter approving a shortage', function () {
    $yard = forecourt();
    $seller = manager();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '100',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '1000.00']],
    ])->assertRedirect();

    $this->post(at("shifts/{$yard['shift']}/submit"), [
        'counted_drawer' => '1090.00',
        'closings' => [['nozzle_id' => $yard['nozzle'], 'reading' => '1100']],
    ])->assertSessionHasNoErrors();

    $shift = $this->asTenant('acme', fn () => Shift::query()->findOrFail($yard['shift']));
    expect($shift->status)->toBe(Shift::CLOSING_SUBMITTED);

    $this->post(at("shifts/{$shift->id}/review"))->assertSessionHasNoErrors();
    $this->post(at("shifts/{$shift->id}/approve"))->assertSessionHasErrors('shift');

    manager();
    expect(manager()->id)->not->toBe($seller->id);
    $this->post(at("shifts/{$shift->id}/approve"))->assertSessionHasNoErrors();

    $approved = $shift->fresh();
    expect($approved->status)->toBe(Shift::APPROVED)
        ->and($approved->cash_difference_minor)->toBe(-1000)
        ->and(financeEvents())->toContain('finance.cash.counted');
});

it('receives a delivery into the tank and posts one bill', function () {
    $yard = forecourt();
    $this->post(at('suppliers'), ['name' => 'Nile Fuel'])->assertSessionHasNoErrors();
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->sole());
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());

    $this->post(at('purchases'), [
        'supplier_id' => $supplier->id,
        'lines' => [['fuel_product_id' => $product->id, 'litres' => '1000', 'unit_cost' => '7.50']],
    ])->assertRedirect();
    $order = $this->asTenant('acme', fn () => PurchaseOrder::query()->sole());
    $line = $order->lines()->sole();

    $this->post(at("purchases/{$order->id}/receive"), [
        'purchase_line_id' => $line->id,
        'tank_id' => $yard['tank'],
        'declared' => '1000',
        'measured' => '990',
        'unit_cost' => '7.50',
    ])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->quantity_milli))->toBe(5_990_000)
        ->and($this->asTenant('acme', fn () => FuelReceipt::query()->count()))->toBe(1)
        ->and(array_count_values(financeEvents())['finance.bill.received'] ?? 0)->toBe(1);
});

it('hides another organization and another branch', function () {
    $yard = forecourt();
    $this->platform()->organization('other');
    $this->asTenant('other', fn () => expect(FuelProduct::query()->count())->toBe(0));

    $foreign = $this->asTenant('acme', fn () => Tank::query()->create([
        'branch_id' => $this->wau,
        'fuel_product_id' => FuelProduct::query()->where('code', 'PMS')->sole()->id,
        'name' => 'Wau tank',
        'code' => 'W1',
        'capacity_milli' => 1_000_000,
        'status' => Tank::ACTIVE,
    ]));

    $this->get(at("tanks/{$foreign->id}"))->assertNotFound();
    $this->get(at("tanks/{$yard['tank']}"))->assertOk();
});

it('blocks a station past the plan limit', function () {
    $user = $this->actingAsMember('acme', petrolPermissions(), features: [Permissions::STATIONS_FEATURE]);
    $org = $this->platform()->organization('acme')['id'];
    $key = 'petrol:'.$org.':'.$user->getAuthIdentifier();
    $this->platform()->access[$key]['entitlement']['features'][0]['limit'] = 1;

    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('settings/station'), [
        'code' => 'JUBA',
        'collection_mode' => 'cashier',
        'low_stock_percent' => 15,
        'active' => 1,
    ])->assertSessionHasNoErrors();

    $this->put(at('branch'), ['branch_id' => $this->wau])->assertRedirect();
    $this->post(at('settings/station'), [
        'code' => 'WAU',
        'collection_mode' => 'cashier',
        'low_stock_percent' => 15,
        'active' => 1,
    ])->assertSessionHasErrors('code');
});

it('applies an offline sale once', function () {
    $yard = forecourt();
    $this->actingAsAppMember('acme', petrolPermissions(), branch: $this->juba);
    $id = (string) Str::uuid();
    $body = [
        'id' => $id,
        'kind' => 'sale.record',
        'payload' => [
            'nozzle_id' => $yard['nozzle'],
            'litres' => '10',
            'attendant_id' => $yard['attendant'],
            'collected_by' => 'cashier',
            'payments' => [['method_id' => $yard['cash'], 'amount' => '100.00']],
        ],
    ];

    $first = $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), $body)->assertOk();
    $again = $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), $body)->assertOk();

    expect($again->json('sale_id'))->toBe($first->json('sale_id'))
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => AppSubmission::query()->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->quantity_milli))->toBe(4_990_000);
});

it('does not store a sale for a shift that is no longer open', function () {
    $yard = forecourt();
    $this->post(at("shifts/{$yard['shift']}/submit"), [
        'counted_drawer' => '100.00',
        'closings' => [['nozzle_id' => $yard['nozzle'], 'reading' => '1000']],
    ])->assertSessionHasNoErrors();

    $this->actingAsAppMember('acme', petrolPermissions(), branch: $this->juba);
    $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), [
        'id' => (string) Str::uuid(),
        'kind' => 'sale.record',
        'payload' => [
            'nozzle_id' => $yard['nozzle'],
            'litres' => '10',
            'attendant_id' => $yard['attendant'],
            'collected_by' => 'cashier',
            'payments' => [['method_id' => $yard['cash'], 'amount' => '100.00']],
        ],
    ])->assertStatus(422);

    expect($this->asTenant('acme', fn () => AppSubmission::query()->count()))->toBe(0)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(0);
});

it('makes the first station the main branch and refuses to close it', function () {
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->juba, 'JUBA');
    openStation($this->wau, 'WAU');

    $stations = $this->asTenant('acme', fn () => Station::query()->pluck('is_main', 'branch_id'));
    expect((bool) $stations[$this->juba])->toBeTrue()
        ->and((bool) $stations[$this->wau])->toBeFalse();

    $this->post(at('settings/station'), [
        'code' => 'WAU',
        'collection_mode' => 'both',
        'low_stock_percent' => 15,
        'active' => 0,
    ])->assertSessionHasNoErrors();

    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('settings/station'), [
        'code' => 'JUBA',
        'collection_mode' => 'both',
        'low_stock_percent' => 15,
        'active' => 0,
    ])->assertSessionHasErrors('active');
});

it('transfers the main branch once and records who did it', function () {
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->juba, 'JUBA');
    openStation($this->wau, 'WAU');

    $this->post(at('overview/main'), ['branch_id' => $this->wau])->assertRedirect();
    $this->post(at('overview/main'), ['branch_id' => $this->wau])->assertRedirect();

    $stations = $this->asTenant('acme', fn () => Station::query()->pluck('is_main', 'branch_id'));
    expect((bool) $stations[$this->wau])->toBeTrue()
        ->and((bool) $stations[$this->juba])->toBeFalse()
        ->and($this->asTenant('acme', fn () => AuditLog::query()->where('action', 'station.main_changed')->count()))->toBe(1);
});

it('keeps the database from storing two main branches', function () {
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->juba, 'JUBA');
    openStation($this->wau, 'WAU');

    expect(fn () => $this->asTenant('acme', function () {
        Station::query()->where('branch_id', $this->wau)->update(['is_main' => true]);
    }))->toThrow(QueryException::class);
});

it('totals authorized stations and hides a station the reporter cannot open', function () {
    $yard = forecourt();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '100',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '1000.00']],
    ])->assertRedirect();

    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->get(at('overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('overview/index')
            ->where('overview.kpis.salesMinor', 100000)
            ->where('overview.kpis.litres', '100.000')
            ->where('overview.kpis.cashMinor', 100000)
            ->where('overview.kpis.shortageMinor', 0)
            ->where('overview.kpis.openShifts', 1)
            ->where('canDesignate', true));

    $this->actingAsMember('acme', [Permissions::REPORTS_VIEW], branch: $this->wau);
    $this->get(at('overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.kpis.salesMinor', 0)
            ->where('overview.kpis.branchCount', 1)
            ->where('canDesignate', false));

    $this->get(at('overview?branch='.$this->juba))->assertNotFound();
});

it('shows each station on the operations board with its shift, sales, staff and what needs someone', function () {
    $yard = forecourt();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '100',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '1000.00']],
    ])->assertRedirect();

    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->wau, 'WAU');

    $response = $this->get(at('overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('overview.stations', 2));
    $board = collect($response->viewData('page')['props']['overview']['stations'])->keyBy('id');

    $juba = $board[$this->juba];
    $wau = $board[$this->wau];
    expect($juba['shift'])->not->toBeNull()
        ->and($juba['salesMinor'])->toBe(100000)
        ->and($juba['count'])->toBe(1)
        ->and($juba['attendants'])->toBe(1)
        ->and($juba['staffedPumps'])->toBe($juba['pumps'])
        ->and($juba['lastSaleAt'])->not->toBeNull()
        ->and($juba['issues'])->not->toContain(['tone' => 'warning', 'text' => 'No shift open.'])
        ->and($wau['shift'])->toBeNull()
        ->and($wau['salesMinor'])->toBe(0)
        ->and($wau['state'])->toBe('attention')
        ->and($wau['issues'])->toContain(['tone' => 'warning', 'text' => 'No shift open.']);
});

it('refuses a branch manager from moving the main branch', function () {
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->juba, 'JUBA');
    openStation($this->wau, 'WAU');

    manager();
    $this->post(at('overview/main'), ['branch_id' => $this->wau])->assertForbidden();

    expect((bool) $this->asTenant('acme', fn () => Station::query()->where('branch_id', $this->juba)->value('is_main')))->toBeTrue();
});

it('keeps a sale on the rate it used after the station rate changes', function () {
    $yard = forecourt();
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7800',
    ])->assertSessionHasNoErrors();
    $this->post(at('settings/prices'), [
        'fuel_product_id' => $product->id,
        'selling_price' => '1.00',
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '1.00']],
    ])->assertRedirect();

    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7700',
    ])->assertSessionHasNoErrors();

    $sale = $this->asTenant('acme', fn () => FuelSale::query()->latest('sold_at')->first());
    expect($sale->currency)->toBe('USD')
        ->and($sale->total_minor)->toBe(100)
        ->and($sale->reporting_total_minor)->toBe(780000)
        ->and($sale->fx_rate_minor)->toBe(780000);
});

it('moves fuel once on dispatch and once on receipt', function () {
    $yard = forecourt();
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->wau, 'WAU');
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->post(at('tanks'), [
        'fuel_product_id' => $product->id,
        'name' => 'Wau tank',
        'code' => 'TW',
        'capacity' => '20000',
        'status' => 'active',
    ])->assertSessionHasNoErrors();
    $destination = $this->asTenant('acme', fn () => Tank::query()->where('code', 'TW')->sole());
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('transfers/fuel'), [
        'source_tank_id' => $yard['tank'],
        'destination_tank_id' => $destination->id,
        'litres' => '100',
    ])->assertSessionHasNoErrors();
    $transfer = $this->asTenant('acme', fn () => FuelTransfer::query()->sole());
    $this->post(at("transfers/fuel/{$transfer->id}/approve"))->assertSessionHasNoErrors();
    $this->post(at("transfers/fuel/{$transfer->id}/dispatch"), ['litres' => '100'])->assertSessionHasNoErrors();
    $this->post(at("transfers/fuel/{$transfer->id}/dispatch"), ['litres' => '100'])->assertSessionHasErrors('transfer');
    $this->put(at('branch'), ['branch_id' => $this->wau])->assertRedirect();
    $this->post(at("transfers/fuel/{$transfer->id}/receive"), ['litres' => '90'])->assertSessionHasNoErrors();
    $this->post(at("transfers/fuel/{$transfer->id}/complete"))->assertSessionHasNoErrors();

    $transfer = $this->asTenant('acme', fn () => FuelTransfer::query()->findOrFail($transfer->id));
    expect($transfer->status)->toBe(FuelTransfer::COMPLETED)
        ->and($transfer->discrepancy_milli)->toBe(-10000)
        ->and($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->quantity_milli))->toBe(4_900_000)
        ->and($this->asTenant('acme', fn () => Tank::query()->findOrFail($destination->id)->quantity_milli))->toBe(90_000)
        ->and($this->asTenant('acme', fn () => StockMovement::query()->where('kind', 'transfer_out')->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => StockMovement::query()->where('kind', 'transfer_in')->count()))->toBe(1);
});

it('issues a service part once and does not take it when it is only estimated', function () {
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->juba, 'JUBA');
    $this->post(at('customers'), [
        'name' => 'Aker',
        'kind' => 'individual',
        'credit_limit' => '0',
    ])->assertSessionHasNoErrors();
    $customer = $this->asTenant('acme', fn () => Customer::query()->sole());
    $this->post(at('service/bays'), ['name' => 'Bay 1', 'code' => 'B1'])->assertSessionHasNoErrors();
    $this->post(at('service/vehicles'), [
        'customer_id' => $customer->id,
        'registration' => 'SS-1',
    ])->assertSessionHasNoErrors();
    $bay = $this->asTenant('acme', fn () => ServiceBay::query()->sole());
    $vehicle = $this->asTenant('acme', fn () => Vehicle::query()->sole());
    $this->post(at('store/stores'), ['name' => 'Shop', 'code' => 'S1'])->assertSessionHasNoErrors();
    $this->post(at('store/products'), [
        'name' => 'Oil filter',
        'code' => 'OF1',
        'unit' => 'piece',
    ])->assertSessionHasNoErrors();
    $store = $this->asTenant('acme', fn () => Store::query()->sole());
    $product = $this->asTenant('acme', fn () => StoreProduct::query()->sole());
    $this->post(at('store/receive'), [
        'store_id' => $store->id,
        'product_id' => $product->id,
        'quantity' => '4',
        'cost' => '5.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
    $this->post(at('service/orders'), [
        'service_bay_id' => $bay->id,
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'technician' => 'Deng',
    ])->assertSessionHasNoErrors();
    $order = $this->asTenant('acme', fn () => WorkOrder::query()->sole());
    $this->post(at("service/orders/{$order->id}/parts"), [
        'store_id' => $store->id,
        'product_id' => $product->id,
        'quantity' => '1',
        'amount' => '8.00',
    ])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => StoreBalance::query()->sole()->quantity_milli))->toBe(4000);
    $line = $this->asTenant('acme', fn () => WorkOrderLine::query()->sole());
    $this->post(at("service/lines/{$line->id}/issue"))->assertSessionHasNoErrors();
    $this->post(at("service/lines/{$line->id}/issue"))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => StoreBalance::query()->sole()->quantity_milli))->toBe(3000)
        ->and($this->asTenant('acme', fn () => StoreMovement::query()->where('kind', 'issue')->count()))->toBe(1);
});

it('lets a company administrator add a category and keeps a branch user from doing so', function () {
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->juba, 'JUBA');
    $this->post(at('expenses/categories'), [
        'name' => 'Electricity',
        'code' => 'ELEC',
        'default_type' => 'recurring',
    ])->assertSessionHasNoErrors();

    manager();
    $this->post(at('expenses/categories'), [
        'name' => 'Rent',
        'code' => 'RENT',
        'default_type' => 'once',
    ])->assertForbidden();
    expect($this->asTenant('acme', fn () => ExpenseCategory::query()->count()))->toBe(1);
});

it('keeps a fuel receipt on the rate it used and values the tank in the workspace currency', function () {
    $yard = forecourt();
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7800',
    ])->assertSessionHasNoErrors();
    $this->post(at('suppliers'), ['name' => 'Nile Fuel'])->assertSessionHasNoErrors();
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->sole());
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->post(at('purchases'), [
        'supplier_id' => $supplier->id,
        'lines' => [['fuel_product_id' => $product->id, 'litres' => '10', 'unit_cost' => '1.00', 'currency' => 'USD']],
    ])->assertRedirect();
    $order = $this->asTenant('acme', fn () => PurchaseOrder::query()->sole());
    $line = $this->asTenant('acme', fn () => $order->lines()->sole());
    $this->post(at("purchases/{$order->id}/receive"), [
        'purchase_line_id' => $line->id,
        'tank_id' => $yard['tank'],
        'declared' => '10',
        'measured' => '10',
        'unit_cost' => '1.00',
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();

    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7700',
    ])->assertSessionHasNoErrors();

    $receipt = $this->asTenant('acme', fn () => FuelReceipt::query()->sole());
    $tank = $this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank']));
    expect($receipt->currency)->toBe('USD')
        ->and($receipt->total_minor)->toBe(1000)
        ->and($receipt->reporting_total_minor)->toBe(7_800_000)
        ->and($receipt->fx_rate_minor)->toBe(780000)
        ->and($receipt->reporting_unit_cost_minor)->toBe(780000)
        ->and($tank->quantity_milli)->toBe(5_010_000)
        ->and($tank->average_cost_minor)->not->toBe(100);
});

it('keeps US dollars out of the SSP drawer', function () {
    $yard = forecourt();
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7800',
    ])->assertSessionHasNoErrors();
    $this->post(at('settings/prices'), [
        'fuel_product_id' => $product->id,
        'selling_price' => '1.00',
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '1.00']],
    ])->assertRedirect();

    $drawers = $this->asTenant('acme', function () use ($yard) {
        $cash = app(Cashbook::class);

        return [$cash->drawer($yard['shift']), $cash->drawer($yard['shift'], 'USD')];
    });
    expect($drawers[0])->toBe(10000)
        ->and($drawers[1])->toBe(100)
        ->and($this->asTenant('acme', fn () => CashMovement::query()->where('currency', 'USD')->count()))->toBe(1);
});

it('records customer credit in the sale currency and does not rewrite it when the rate changes', function () {
    $yard = forecourt();
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7800',
    ])->assertSessionHasNoErrors();
    $this->post(at('settings/prices'), [
        'fuel_product_id' => $product->id,
        'selling_price' => '1.00',
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();
    $this->post(at('customers'), [
        'name' => 'Aker',
        'kind' => 'individual',
        'credit_limit' => '8000.00',
    ])->assertSessionHasNoErrors();
    $customer = $this->asTenant('acme', fn () => Customer::query()->sole());
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['credit'], 'amount' => '1.00', 'customer_id' => $customer->id]],
    ])->assertSessionHasNoErrors();
    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7700',
    ])->assertSessionHasNoErrors();
    $this->post(at("customers/{$customer->id}/payments"), [
        'amount' => '1.00',
        'currency' => 'USD',
        'method_id' => $yard['cash'],
    ])->assertSessionHasNoErrors();

    $entries = $this->asTenant('acme', fn () => CreditEntry::query()->get());
    $charge = $entries->firstWhere('kind', 'sale');
    $payment = $entries->firstWhere('kind', 'payment');
    expect($entries)->toHaveCount(2)
        ->and($charge->fx_rate_minor)->toBe(780000)
        ->and($charge->reporting_amount_minor)->toBe(780000)
        ->and($payment->fx_rate_minor)->toBe(770000)
        ->and($payment->reporting_amount_minor)->toBe(-770000)
        ->and($this->asTenant('acme', fn () => app(Credit::class)->balance($customer->id)))->toBe(10000);
});

it('generates each missed recurring expense once and skips a paused schedule', function () {
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->juba, 'JUBA');
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('expenses/categories'), [
        'name' => 'Rent',
        'code' => 'RENT',
        'default_type' => 'recurring',
    ])->assertSessionHasNoErrors();
    $category = $this->asTenant('acme', fn () => ExpenseCategory::query()->sole());
    $this->post(at('expenses/schedules'), [
        'category_id' => $category->id,
        'frequency' => 'daily',
        'starts_on' => '2026-11-06',
        'amount' => '10.00',
        'description' => 'Daily rent',
    ])->assertSessionHasNoErrors();
    $this->post(at('expenses/schedules/generate'))->assertSessionHasNoErrors();
    $this->post(at('expenses/schedules/generate'))->assertSessionHasNoErrors();

    $schedule = $this->asTenant('acme', fn () => ExpenseSchedule::query()->sole());
    expect($this->asTenant('acme', fn () => Expense::query()->count()))->toBe(3)
        ->and($schedule->next_due_on->toDateString())->toBe('2026-11-09')
        ->and($this->asTenant('acme', fn () => Expense::query()->where('payment_status', 'unpaid')->count()))->toBe(3);

    $this->post(at('expenses/schedules'), [
        'category_id' => $category->id,
        'frequency' => 'daily',
        'starts_on' => '2026-11-08',
        'amount' => '5.00',
        'description' => 'Paused rent',
    ])->assertSessionHasNoErrors();
    $paused = $this->asTenant('acme', fn () => ExpenseSchedule::query()->where('description', 'Paused rent')->sole());
    $this->post(at("expenses/schedules/{$paused->id}/pause"))->assertSessionHasNoErrors();
    $this->post(at('expenses/schedules/generate'))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => Expense::query()->count()))->toBe(3);
});

it('applies an offline expense once and refuses a second open shift without storing it', function () {
    forecourt();
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('expenses/categories'), [
        'name' => 'Security',
        'code' => 'SEC',
        'default_type' => 'once',
    ])->assertSessionHasNoErrors();
    $category = $this->asTenant('acme', fn () => ExpenseCategory::query()->sole());
    $this->actingAsAppMember('acme', petrolPermissions(), branch: $this->juba);
    $id = (string) Str::uuid();
    $body = [
        'id' => $id,
        'kind' => 'expense.record',
        'payload' => [
            'category_id' => $category->id,
            'amount' => '15.00',
            'description' => 'Night guard',
            'paid_from' => 'bank',
        ],
    ];
    $first = $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), $body)->assertOk();
    $again = $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), $body)->assertOk();
    expect($again->json('expense_id'))->toBe($first->json('expense_id'))
        ->and($this->asTenant('acme', fn () => Expense::query()->count()))->toBe(1);

    $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), [
        'id' => (string) Str::uuid(),
        'kind' => 'shift.open',
        'payload' => ['opening_float' => '50.00', 'openings' => []],
    ])->assertStatus(422);
    expect($this->asTenant('acme', fn () => AppSubmission::query()->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => Shift::query()->where('status', Shift::OPEN)->count()))->toBe(1);
});

it('keeps attendant cash until the cashier counts it, then moves only the counted amount', function () {
    $yard = forecourt();
    $cashier = auth()->user();
    $seller = $this->platformUser(['name' => 'Amina Seller']);
    $custodian = $this->platformUser(['name' => 'Safe Custodian']);
    $this->asTenant('acme', fn () => Attendant::query()->whereKey($yard['attendant'])->update(['user_id' => $seller->id]));

    $this->actingAsMember('acme', petrolPermissions(), user: $seller, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '10',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'attendant',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '100.00']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $key = (string) Str::uuid();
    $remit = [
        'attendant_id' => $yard['attendant'],
        'cashier_id' => $cashier->id,
        'amount' => '60.00',
        'idempotency_key' => $key,
    ];
    $this->post(at('cash/remittances'), $remit)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(at('cash/remittances'), $remit)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(at('cash/remittances'), [
        'attendant_id' => $yard['attendant'],
        'cashier_id' => $cashier->id,
        'amount' => '40.00',
    ])->assertSessionHasNoErrors();
    $this->post(at('cash/remittances'), [
        'attendant_id' => $yard['attendant'],
        'cashier_id' => $cashier->id,
        'amount' => '10.00',
    ])->assertSessionHasNoErrors();

    $heldWhilePending = $this->asTenant('acme', fn () => app(Custody::class)->attendantHeld($yard['shift'], $yard['attendant'], 'SSP'));
    expect($heldWhilePending)->toBe(10000)
        ->and($this->asTenant('acme', fn () => CashTransfer::query()->where('idempotency_key', $key)->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(1);

    $sixty = $this->asTenant('acme', fn () => CashTransfer::query()->where('declared_minor', 6000)->sole());
    $forty = $this->asTenant('acme', fn () => CashTransfer::query()->where('declared_minor', 4000)->sole());
    $this->actingAsMember('acme', petrolPermissions(), user: $seller, branch: $this->juba);
    $this->post(at("cash/transfers/{$sixty->id}/confirm"), ['counted' => '60.00'])->assertSessionHasErrors('transfer');

    $events = count(financeEvents());
    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("cash/transfers/{$sixty->id}/confirm"), ['counted' => '60.00'])->assertSessionHasNoErrors();
    $this->post(at("cash/transfers/{$sixty->id}/confirm"), ['counted' => '60.00'])->assertSessionHasNoErrors();
    $this->post(at("cash/transfers/{$forty->id}/confirm"), ['counted' => '30.00'])->assertSessionHasNoErrors();

    $disputed = $this->asTenant('acme', fn () => CashTransfer::query()->whereKey($forty->id)->sole());
    $drawers = $this->asTenant('acme', function () use ($yard) {
        $cash = app(Cashbook::class);

        return [$cash->drawer($yard['shift']), $cash->drawer($yard['shift'], 'USD')];
    });
    expect($disputed->status)->toBe(CashTransfer::DISPUTED)
        ->and($disputed->declared_minor)->toBe(4000)
        ->and($disputed->counted_minor)->toBe(3000)
        ->and($drawers[0])->toBe(19000)
        ->and($drawers[1])->toBe(0)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->attendantHeld($yard['shift'], $yard['attendant'], 'SSP')))->toBe(1000)
        ->and($this->asTenant('acme', fn () => CashMovement::query()->where('kind', CashMovement::REMITTANCE)->count()))->toBe(2)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(1)
        ->and(count(financeEvents()))->toBe($events);

    $pending = $this->asTenant('acme', fn () => CashTransfer::query()->where('declared_minor', 1000)->sole());
    $this->post(at("cash/transfers/{$pending->id}/confirm"), ['counted' => '20.00'])->assertSessionHasErrors('counted');
    expect($this->asTenant('acme', fn () => CashTransfer::query()->whereKey($pending->id)->value('status')))->toBe(CashTransfer::PENDING);

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at('cash/safes'), [
        'name' => 'Front safe',
        'code' => 'SF1',
        'custodian_user_id' => $custodian->id,
    ])->assertSessionHasNoErrors();
    $this->post(at('cash/safes'), [
        'name' => 'Back safe',
        'code' => 'SF2',
        'custodian_user_id' => $seller->id,
    ])->assertSessionHasNoErrors();
    $safe = $this->asTenant('acme', fn () => Safe::query()->where('code', 'SF1')->sole());
    $other = $this->asTenant('acme', fn () => Safe::query()->where('code', 'SF2')->sole());
    $this->post(at('cash/safes/transfer'), ['safe_id' => $safe->id, 'amount' => '50.00'])->assertSessionHasNoErrors();
    $toSafe = $this->asTenant('acme', fn () => CashTransfer::query()->where('kind', CashTransfer::TO_SAFE)->sole());
    $this->post(at("cash/transfers/{$toSafe->id}/confirm"), ['counted' => '50.00'])->assertSessionHasErrors('transfer');

    $this->actingAsMember('acme', petrolPermissions(), user: $custodian, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("cash/transfers/{$toSafe->id}/confirm"), ['counted' => '50.00'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'])))->toBe(14000)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($safe->fresh(), 'SSP')))->toBe(5000);

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $depositKey = (string) Str::uuid();
    $this->post(at('cash/deposits'), [
        'safe_id' => $safe->id,
        'bank_reference' => 'KCB-100',
        'amount' => '20.00',
        'deposit_on' => '2026-11-08',
        'slip_reference' => 'SLIP-20',
        'idempotency_key' => $depositKey,
    ])->assertSessionHasNoErrors();
    $this->post(at('cash/deposits'), [
        'safe_id' => $safe->id,
        'bank_reference' => 'KCB-100',
        'amount' => '20.00',
        'deposit_on' => '2026-11-08',
        'idempotency_key' => $depositKey,
    ])->assertSessionHasNoErrors();
    $deposit = $this->asTenant('acme', fn () => BankDeposit::query()->where('idempotency_key', $depositKey)->sole());
    expect($deposit->status)->toBe(BankDeposit::PREPARED)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($safe->fresh(), 'SSP')))->toBe(5000);

    $this->post(at("cash/deposits/{$deposit->id}/submit"))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($safe->fresh(), 'SSP')))->toBe(3000);

    $this->post(at("cash/deposits/{$deposit->id}/confirm"))->assertSessionHasErrors('deposit');
    $this->actingAsMember('acme', petrolPermissions(), user: $seller, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("cash/deposits/{$deposit->id}/confirm"))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => BankDeposit::query()->whereKey($deposit->id)->value('status')))->toBe(BankDeposit::CONFIRMED)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($safe->fresh(), 'SSP')))->toBe(3000)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(1);

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at('cash/deposits'), [
        'safe_id' => $safe->id,
        'bank_reference' => 'KCB-101',
        'amount' => '10.00',
        'deposit_on' => '2026-11-08',
    ])->assertSessionHasNoErrors();
    $returned = $this->asTenant('acme', fn () => BankDeposit::query()->where('bank_reference', 'KCB-101')->sole());
    $this->post(at("cash/deposits/{$returned->id}/submit"))->assertSessionHasNoErrors();
    $this->actingAsMember('acme', petrolPermissions(), user: $seller, branch: $this->juba);
    $this->post(at("cash/deposits/{$returned->id}/reject"), ['notes' => 'Slip did not match'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($safe->fresh(), 'SSP')))->toBe(3000);

    $this->actingAsMember('acme', petrolPermissions(), user: $custodian, branch: $this->juba);
    $this->post(at('cash/counts'), [
        'holder_type' => 'safe',
        'holder_id' => $safe->id,
        'counted' => '25.00',
    ])->assertSessionHasNoErrors();
    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at('cash/counts'), [
        'holder_type' => 'cashier',
        'holder_id' => $cashier->id,
        'counted' => '145.00',
    ])->assertSessionHasNoErrors();
    $count = $this->asTenant('acme', fn () => CashCount::query()->where('holder_type', 'safe')->sole());
    expect($count->expected_minor)->toBe(3000)
        ->and($count->difference_minor)->toBe(-500);

    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->get(at('overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.operations.attendantHeldMinor', 1000)
            ->where('overview.operations.cashierHeldMinor', 14000)
            ->where('overview.operations.safeHeldMinor', 3000)
            ->where('overview.operations.depositsInTransitMinor', 0)
            ->where('overview.operations.depositsConfirmedMinor', 2000)
            ->where('overview.operations.remittedMinor', 9000)
            ->where('overview.operations.pendingTransfers', 1)
            ->where('overview.operations.shortageMinor', 1500)
            ->where('overview.operations.overageMinor', 500));

    $detail = $this->asTenant('acme', function () use ($yard) {
        $shift = Shift::query()->whereKey($yard['shift'])->firstOrFail();

        return app(Reconciliation::class)->detail($shift);
    });
    expect($detail['attendant_expected_minor'])->toBe(1000)
        ->and($detail['expected_minor'])->toBe(15000);

    $this->actingAsMember('acme', petrolPermissions(), user: $custodian, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('cash/safes/move'), [
        'source_safe_id' => $safe->id,
        'destination_safe_id' => $other->id,
        'amount' => '10.00',
    ])->assertSessionHasNoErrors();
    $moved = $this->asTenant('acme', fn () => CashTransfer::query()->where('kind', CashTransfer::BETWEEN_SAFES)->sole());
    $this->actingAsMember('acme', petrolPermissions(), user: $seller, branch: $this->juba);
    $this->post(at("cash/transfers/{$moved->id}/confirm"), ['counted' => '10.00'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($safe->fresh(), 'SSP')))->toBe(2000)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($other->fresh(), 'SSP')))->toBe(1000);

    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->wau])->assertRedirect();
    $this->post(at("cash/transfers/{$pending->id}/confirm"), ['counted' => '10.00'])->assertNotFound();

    $this->actingAsMember('acme', [Permissions::SALES_VIEW], branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('cash/remittances'), [
        'attendant_id' => $yard['attendant'],
        'cashier_id' => $cashier->id,
        'amount' => '1.00',
    ])->assertForbidden();
});

it('rejects a handover without moving the cash', function () {
    $yard = forecourt();
    $cashier = auth()->user();
    $seller = $this->platformUser(['name' => 'Amina Seller']);
    $this->asTenant('acme', fn () => Attendant::query()->whereKey($yard['attendant'])->update(['user_id' => $seller->id]));
    $this->actingAsMember('acme', petrolPermissions(), user: $seller, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '10',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'attendant',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '100.00']],
    ])->assertSessionHasNoErrors();
    $this->post(at('cash/remittances'), [
        'attendant_id' => $yard['attendant'],
        'cashier_id' => $cashier->id,
        'amount' => '25.00',
    ])->assertSessionHasNoErrors();
    $transfer = $this->asTenant('acme', fn () => CashTransfer::query()->sole());
    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at("cash/transfers/{$transfer->id}/reject"), ['notes' => 'Short on the count'])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => CashTransfer::query()->sole()->status))->toBe(CashTransfer::REJECTED)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->attendantHeld($yard['shift'], $yard['attendant'], Money::currency())))->toBe(10000)
        ->and($this->asTenant('acme', fn () => CashMovement::query()->where('kind', CashMovement::REMITTANCE)->count()))->toBe(0);
});

it('keeps a US dollar handover on the rate copied when it was counted', function () {
    $yard = forecourt();
    $cashier = auth()->user();
    $seller = $this->platformUser(['name' => 'Amina Seller']);
    $this->asTenant('acme', fn () => Attendant::query()->whereKey($yard['attendant'])->update(['user_id' => $seller->id]));
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7800',
    ])->assertSessionHasNoErrors();
    $this->post(at('settings/prices'), [
        'fuel_product_id' => $product->id,
        'selling_price' => '1.00',
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', petrolPermissions(), user: $seller, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'attendant',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '1.00']],
    ])->assertSessionHasNoErrors();
    $this->post(at('cash/remittances'), [
        'attendant_id' => $yard['attendant'],
        'cashier_id' => $cashier->id,
        'amount' => '1.00',
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();
    $transfer = $this->asTenant('acme', fn () => CashTransfer::query()->sole());
    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at("cash/transfers/{$transfer->id}/confirm"), ['counted' => '1.00'])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7700',
    ])->assertSessionHasNoErrors();

    $stored = $this->asTenant('acme', fn () => CashTransfer::query()->whereKey($transfer->id)->sole());
    $drawers = $this->asTenant('acme', function () use ($yard) {
        $cash = app(Cashbook::class);

        return [$cash->drawer($yard['shift']), $cash->drawer($yard['shift'], 'USD')];
    });
    expect($stored->fx_rate_minor)->toBe(780000)
        ->and($stored->currency)->toBe('USD')
        ->and($drawers[0])->toBe(10000)
        ->and($drawers[1])->toBe(100)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->attendantHeld($yard['shift'], $yard['attendant'], 'SSP')))->toBe(0);
});

it('stores an offline remittance as pending and does not count it twice', function () {
    $yard = forecourt();
    $cashier = auth()->user();
    $seller = $this->actingAsAppMember('acme', [Permissions::CASH_SUBMIT], branch: $this->juba);
    $this->asTenant('acme', fn () => Attendant::query()->whereKey($yard['attendant'])->update(['user_id' => $seller->id]));
    $id = (string) Str::uuid();
    $body = [
        'id' => $id,
        'kind' => 'cash.remit',
        'payload' => [
            'attendant_id' => $yard['attendant'],
            'cashier_id' => $cashier->id,
            'amount' => '5.00',
        ],
    ];
    $first = $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), $body)->assertOk();
    $again = $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), $body)->assertOk();

    expect($again->json('transfer_id'))->toBe($first->json('transfer_id'))
        ->and($again->json('status'))->toBe(CashTransfer::PENDING)
        ->and($this->asTenant('acme', fn () => AppSubmission::query()->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => CashTransfer::query()->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => CashMovement::query()->where('kind', CashMovement::REMITTANCE)->count()))->toBe(0)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(0);
});

it('puts a cashier cash sale in the drawer once and takes it back when the sale is voided', function () {
    $yard = forecourt();
    $key = (string) Str::uuid();
    $payload = [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '10',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'idempotency_key' => $key,
        'payments' => [['method_id' => $yard['cash'], 'amount' => '100.00']],
    ];
    $this->post(at('sales'), $payload)->assertSessionHasNoErrors();
    $this->post(at('sales'), $payload)->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'])))->toBe(20000)
        ->and($this->asTenant('acme', fn () => CashMovement::query()->where('kind', CashMovement::SALE)->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(1);

    $sale = $this->asTenant('acme', fn () => FuelSale::query()->sole());
    $this->post(at("sales/{$sale->id}/void"), ['reason' => 'Wrong nozzle'])->assertSessionHasNoErrors();
    $this->post(at("sales/{$sale->id}/void"), ['reason' => 'Wrong nozzle'])->assertSessionHasErrors('reason');

    expect($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'])))->toBe(10000)
        ->and($this->asTenant('acme', fn () => CashMovement::query()->where('kind', CashMovement::SALE_VOID)->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->where('status', FuelSale::VOIDED)->count()))->toBe(1);
});

it('keeps a split payment and a US dollar sale in their own cash balances', function () {
    $yard = forecourt();
    $momo = $this->asTenant('acme', fn () => PaymentMethod::query()->where('code', 'MOMO')->sole());
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '10',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [
            ['method_id' => $yard['cash'], 'amount' => '60.00'],
            ['method_id' => $momo->id, 'amount' => '40.00'],
        ],
    ])->assertSessionHasNoErrors();
    $split = $this->asTenant('acme', fn () => FuelSale::query()->sole());
    $this->post(at("sales/{$split->id}/void"), ['reason' => 'Mixed tender'])->assertSessionHasErrors('reason');

    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7800',
    ])->assertSessionHasNoErrors();
    $this->post(at('settings/prices'), [
        'fuel_product_id' => $product->id,
        'selling_price' => '1.00',
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '1.00']],
    ])->assertSessionHasNoErrors();
    $dollar = $this->asTenant('acme', fn () => FuelSale::query()->where('currency', 'USD')->sole());
    $this->post(at("sales/{$dollar->id}/void"), ['reason' => 'Wrong currency'])->assertSessionHasNoErrors();

    $drawers = $this->asTenant('acme', function () use ($yard) {
        $cash = app(Cashbook::class);

        return [$cash->drawer($yard['shift']), $cash->drawer($yard['shift'], 'USD')];
    });
    expect($drawers)->toBe([16000, 0])
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(2)
        ->and($this->asTenant('acme', fn () => CashMovement::query()->where('kind', CashMovement::SALE)->count()))->toBe(2);
});

it('pays cash out once, adjusts the drawer once, and keeps the earlier count', function () {
    $yard = forecourt();
    $cashier = auth()->user();
    $approver = $this->platformUser(['name' => 'Approver']);
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '10',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '100.00']],
    ])->assertSessionHasNoErrors();

    $key = (string) Str::uuid();
    $payout = [
        'approver_id' => $approver->id,
        'amount' => '40.00',
        'category' => 'Station expense',
        'payee' => 'Night guard',
        'description' => 'Gate',
        'receipt' => 'R-9',
        'idempotency_key' => $key,
    ];
    $this->post(at('cash/payouts'), $payout)->assertSessionHasNoErrors();
    $this->post(at('cash/payouts'), $payout)->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'])))->toBe(20000)
        ->and($this->asTenant('acme', fn () => Expense::query()->count()))->toBe(0)
        ->and($this->asTenant('acme', fn () => CashTransfer::query()->where('idempotency_key', $key)->count()))->toBe(1);

    $transfer = $this->asTenant('acme', fn () => CashTransfer::query()->where('kind', CashTransfer::PAYOUT)->sole());
    expect($transfer->notes)->toContain('Night guard');
    $this->post(at("cash/transfers/{$transfer->id}/confirm"), ['counted' => '40.00'])->assertSessionHasErrors('transfer');
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("cash/transfers/{$transfer->id}/confirm"), ['counted' => '40.00'])->assertSessionHasNoErrors();
    $this->post(at("cash/transfers/{$transfer->id}/confirm"), ['counted' => '40.00'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'])))->toBe(16000)
        ->and($this->asTenant('acme', fn () => CashMovement::query()->where('kind', CashMovement::PAYOUT)->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(1);

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at('cash/safes'), [
        'name' => 'Front safe',
        'code' => 'SF1',
        'custodian_user_id' => $approver->id,
    ])->assertSessionHasNoErrors();
    $safe = $this->asTenant('acme', fn () => Safe::query()->where('code', 'SF1')->sole());
    $this->post(at('cash/safes/transfer'), ['safe_id' => $safe->id, 'amount' => '30.00'])->assertSessionHasNoErrors();
    $toSafe = $this->asTenant('acme', fn () => CashTransfer::query()->where('kind', CashTransfer::TO_SAFE)->sole());
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->post(at("cash/transfers/{$toSafe->id}/confirm"), ['counted' => '30.00'])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at('cash/payouts'), [
        'approver_id' => $approver->id,
        'safe_id' => $safe->id,
        'amount' => '10.00',
        'category' => 'Other',
        'payee' => 'Driver',
        'receipt' => 'R-10',
    ])->assertSessionHasNoErrors();
    $safePayout = $this->asTenant('acme', fn () => CashTransfer::query()->where('source_type', CashTransfer::SAFE)->where('kind', CashTransfer::PAYOUT)->sole());
    expect($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($safe->fresh(), 'SSP')))->toBe(3000);
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->post(at("cash/transfers/{$safePayout->id}/confirm"), ['counted' => '10.00'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($safe->fresh(), 'SSP')))->toBe(2000)
        ->and($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'])))->toBe(13000);

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at('cash/counts'), [
        'holder_type' => 'cashier',
        'holder_id' => $cashier->id,
        'counted' => '120.00',
    ])->assertSessionHasNoErrors();
    $this->post(at('cash/adjustments'), [
        'approver_id' => $approver->id,
        'amount' => '5.00',
        'direction' => 'out',
        'type' => 'verified shortage',
        'reason' => 'Note was missing',
    ])->assertSessionHasNoErrors();
    $adjustment = $this->asTenant('acme', fn () => CashTransfer::query()->where('kind', CashTransfer::ADJUSTMENT)->sole());
    expect($adjustment->notes)->toContain('Balance before')
        ->and($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'])))->toBe(13000);
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->post(at("cash/transfers/{$adjustment->id}/confirm"), ['counted' => '5.00'])->assertSessionHasNoErrors();
    $this->post(at("cash/transfers/{$adjustment->id}/confirm"), ['counted' => '5.00'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'])))->toBe(12500)
        ->and($this->asTenant('acme', fn () => CashMovement::query()->where('kind', CashMovement::ADJUSTMENT)->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => CashCount::query()->sole()->difference_minor))->toBe(-1000);

    $this->actingAsMember('acme', [Permissions::SALES_VIEW], branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('cash/payouts'), [
        'approver_id' => $approver->id,
        'amount' => '1.00',
        'category' => 'Other',
        'payee' => 'Someone',
    ])->assertForbidden();
});

it('shows each currency on its own and a labelled reporting total', function () {
    $yard = forecourt();
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7800',
    ])->assertSessionHasNoErrors();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '10.00']],
    ])->assertSessionHasNoErrors();
    $this->post(at('settings/prices'), [
        'fuel_product_id' => $product->id,
        'selling_price' => '1.00',
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '1.00']],
    ])->assertSessionHasNoErrors();

    $this->get(at('overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.operations.custodyCurrencies', function ($rows) {
                $ssp = collect($rows)->firstWhere('currency', 'SSP');
                $usd = collect($rows)->firstWhere('currency', 'USD');

                return $ssp['cashier'] === 11000
                    && $usd['cashier'] === 100
                    && $ssp['shortage'] === 0
                    && $usd['overage'] === 0;
            })
            ->where('overview.operations.custodyEquivalent.cashier', 791000)
            ->where('overview.operations.custodyEquivalent.complete', true)
            ->where('overview.kpis.salesMinor', 780000 + 1000));
});

it('keeps a short bank confirmation without putting the difference back in the safe', function () {
    $yard = forecourt();
    $cashier = auth()->user();
    $approver = $this->platformUser(['name' => 'Bank checker']);
    $this->post(at('cash/safes'), [
        'name' => 'Front safe',
        'code' => 'SF1',
        'custodian_user_id' => $approver->id,
    ])->assertSessionHasNoErrors();
    $safe = $this->asTenant('acme', fn () => Safe::query()->sole());
    $this->post(at('cash/safes/transfer'), ['safe_id' => $safe->id, 'amount' => '50.00'])->assertSessionHasNoErrors();
    $toSafe = $this->asTenant('acme', fn () => CashTransfer::query()->sole());
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("cash/transfers/{$toSafe->id}/confirm"), ['counted' => '50.00'])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at('cash/deposits'), [
        'safe_id' => $safe->id,
        'bank_name' => 'KCB',
        'bank_reference' => 'ACC-1',
        'amount' => '50.00',
        'deposit_on' => '2026-11-08',
        'slip_reference' => 'SLIP-1',
    ])->assertSessionHasNoErrors();
    $deposit = $this->asTenant('acme', fn () => BankDeposit::query()->sole());
    $this->post(at("cash/deposits/{$deposit->id}/submit"))->assertSessionHasNoErrors();
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->post(at("cash/deposits/{$deposit->id}/confirm"), ['confirmed' => '45.00'])->assertSessionHasNoErrors();
    $this->post(at("cash/deposits/{$deposit->id}/confirm"), ['confirmed' => '45.00'])->assertSessionHasNoErrors();

    $stored = $this->asTenant('acme', fn () => BankDeposit::query()->sole());
    expect($stored->amount_minor)->toBe(5000)
        ->and($stored->confirmed_amount_minor)->toBe(4500)
        ->and($stored->bank_name)->toBe('KCB')
        ->and($stored->status)->toBe(BankDeposit::CONFIRMED)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($safe->fresh(), 'SSP')))->toBe(0)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(0);

    $detail = $this->asTenant('acme', function () use ($yard) {
        return app(Reconciliation::class)->detail(Shift::query()->whereKey($yard['shift'])->firstOrFail());
    });
    expect($detail['expected_minor'])->toBe(5000);
});

it('starts with SSP and USD and lets only a company administrator add a currency', function () {
    forecourt();
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->get(at('currencies'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('catalog', function ($rows) {
            $codes = collect($rows)->pluck('code')->all();

            return $codes === ['SSP', 'USD'];
        }));

    $this->post(at('currencies'), [
        'name' => 'Kenyan Shilling',
        'code' => 'kes',
        'symbol' => 'KSh',
        'decimals' => 2,
    ])->assertSessionHasNoErrors();
    $this->post(at('currencies'), [
        'name' => 'Kenyan Shilling again',
        'code' => 'KES',
        'symbol' => 'KSh',
        'decimals' => 2,
    ])->assertSessionHasErrors('code');

    $this->actingAsMember('acme', petrolPermissions(), branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('currencies'), [
        'name' => 'Ugandan Shilling',
        'code' => 'UGX',
        'symbol' => 'USh',
        'decimals' => 0,
    ])->assertForbidden();
    $this->put(at('currencies-station'), ['enabled' => ['SSP', 'KES']])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Currencies::class)->acceptedCodes($this->juba)))->toBe(['KES', 'SSP']);

    $this->actingAsMember('acme', [Permissions::SALES_VIEW], branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('currencies'), [
        'name' => 'Ugandan Shilling',
        'code' => 'UGX',
        'symbol' => 'USh',
        'decimals' => 0,
    ])->assertForbidden();

    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->wau])->assertRedirect();
    openStation($this->wau, 'WAU');
    $this->put(at('currencies-station'), ['enabled' => ['SSP']])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Currencies::class)->acceptedCodes($this->wau)))->toBe(['SSP'])
        ->and($this->asTenant('acme', fn () => app(Currencies::class)->acceptedCodes($this->juba)))->toBe(['KES', 'SSP']);

    $other = $this->asTenant('beta', function () {
        Workspace::query()->create([
            'display_name' => 'Beta',
            'currency' => 'SSP',
            'timezone' => 'Africa/Juba',
        ]);

        return app(Currencies::class)->codes();
    });
    expect($other)->toBe(['SSP', 'USD'])
        ->and($this->asTenant('acme', fn () => Currency::query()->where('code', 'UGX')->exists()))->toBeFalse();
});

it('keeps each currency separate through sales, handovers, safes, deposits and the dashboard', function () {
    $yard = forecourt();
    $cashier = auth()->user();
    $seller = $this->platformUser(['name' => 'Amina Seller']);
    $approver = $this->platformUser(['name' => 'Safe Custodian']);
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->asTenant('acme', fn () => Attendant::query()->whereKey($yard['attendant'])->update(['user_id' => $seller->id]));

    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('currencies'), [
        'name' => 'Kenyan Shilling',
        'code' => 'KES',
        'symbol' => 'KSh',
        'decimals' => 2,
    ])->assertSessionHasNoErrors();
    $this->put(at('currencies-station'), ['enabled' => ['SSP', 'USD', 'KES']])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7800',
    ])->assertSessionHasNoErrors();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '10.00']],
    ])->assertSessionHasNoErrors();
    $this->post(at('settings/prices'), [
        'fuel_product_id' => $product->id,
        'selling_price' => '1.00',
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', petrolPermissions(), user: $seller, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '2',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'attendant',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '2.00']],
    ])->assertSessionHasNoErrors();
    $this->post(at('cash/remittances'), [
        'attendant_id' => $yard['attendant'],
        'cashier_id' => $cashier->id,
        'amount' => '1.50',
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Custody::class)->attendantHeld($yard['shift'], $yard['attendant'], 'USD')))->toBe(200);

    $remittance = $this->asTenant('acme', fn () => CashTransfer::query()->where('kind', CashTransfer::REMITTANCE)->sole());
    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("cash/transfers/{$remittance->id}/confirm"), ['counted' => '1.50'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => app(Custody::class)->attendantHeld($yard['shift'], $yard['attendant'], 'USD')))->toBe(50)
        ->and($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'], 'USD')))->toBe(150)
        ->and($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'])))->toBe(11000);

    $this->post(at('cash/adjustments'), [
        'approver_id' => $approver->id,
        'amount' => '20.00',
        'direction' => 'in',
        'type' => 'other',
        'reason' => 'KES float',
        'currency' => 'KES',
    ])->assertSessionHasNoErrors();
    $adjustment = $this->asTenant('acme', fn () => CashTransfer::query()->where('kind', CashTransfer::ADJUSTMENT)->sole());
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("cash/transfers/{$adjustment->id}/confirm"), ['counted' => '20.00'])->assertSessionHasNoErrors();
    expect($adjustment->fresh()->fx_rate_minor)->toBeNull()
        ->and($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'], 'KES')))->toBe(2000);

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at('cash/safes'), [
        'name' => 'Front safe',
        'code' => 'SF1',
        'custodian_user_id' => $approver->id,
    ])->assertSessionHasNoErrors();
    $this->post(at('cash/safes'), [
        'name' => 'Back safe',
        'code' => 'SF2',
        'custodian_user_id' => $approver->id,
    ])->assertSessionHasNoErrors();
    $safes = $this->asTenant('acme', fn () => Safe::query()->pluck('id', 'code'));
    $this->post(at('cash/safes/transfer'), [
        'safe_id' => $safes['SF1'],
        'amount' => '10.00',
        'currency' => 'KES',
    ])->assertSessionHasNoErrors();
    $toSafe = $this->asTenant('acme', fn () => CashTransfer::query()->where('kind', CashTransfer::TO_SAFE)->sole());
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->post(at("cash/transfers/{$toSafe->id}/confirm"), ['counted' => '10.00'])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at('cash/safes/move'), [
        'source_safe_id' => $safes['SF1'],
        'destination_safe_id' => $safes['SF2'],
        'amount' => '5.00',
        'currency' => 'KES',
    ])->assertSessionHasNoErrors();
    $moved = $this->asTenant('acme', fn () => CashTransfer::query()->where('kind', CashTransfer::BETWEEN_SAFES)->sole());
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->post(at("cash/transfers/{$moved->id}/confirm"), ['counted' => '5.00'])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at('cash/deposits'), [
        'safe_id' => $safes['SF2'],
        'bank_name' => 'KCB',
        'bank_reference' => 'KES-1',
        'amount' => '5.00',
        'currency' => 'KES',
        'deposit_on' => '2026-11-08',
        'slip_reference' => 'SLIP-KES',
    ])->assertSessionHasNoErrors();
    $deposit = $this->asTenant('acme', fn () => BankDeposit::query()->sole());
    $this->post(at("cash/deposits/{$deposit->id}/submit"))->assertSessionHasNoErrors();
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->post(at("cash/deposits/{$deposit->id}/confirm"), ['confirmed' => '5.00'])->assertSessionHasNoErrors();

    $front = $this->asTenant('acme', fn () => Safe::query()->where('code', 'SF1')->sole());
    $back = $this->asTenant('acme', fn () => Safe::query()->where('code', 'SF2')->sole());
    expect($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($front, 'KES')))->toBe(500)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($back, 'KES')))->toBe(0)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->safeBalance($front, 'SSP')))->toBe(0)
        ->and($this->asTenant('acme', fn () => app(Cashbook::class)->drawer($yard['shift'], 'KES')))->toBe(1000)
        ->and($deposit->fresh()->currency)->toBe('KES');

    $this->actingAsMember('acme', petrolPermissions(), user: $cashier, branch: $this->juba);
    $this->post(at('cash/counts'), [
        'holder_type' => 'cashier',
        'holder_id' => $cashier->id,
        'counted' => '110.00',
        'currency' => 'SSP',
    ])->assertSessionHasNoErrors();
    $this->post(at('cash/counts'), [
        'holder_type' => 'cashier',
        'holder_id' => $cashier->id,
        'counted' => '0.00',
        'currency' => 'USD',
    ])->assertSessionHasNoErrors();
    $counts = $this->asTenant('acme', fn () => CashCount::query()->get());
    $sspCount = $counts->first(fn (CashCount $row) => $row->currency === null || $row->currency === 'SSP');
    $usdCount = $counts->firstWhere('currency', 'USD');
    expect($sspCount->difference_minor)->toBe(0)
        ->and($usdCount->difference_minor)->toBe(-150);

    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('rates'), [
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate' => '7700',
    ])->assertSessionHasNoErrors();
    $usdSale = $this->asTenant('acme', fn () => FuelSale::query()->where('currency', 'USD')->sole());
    expect($usdSale->fx_rate_minor)->toBe(780000)
        ->and($remittance->fresh()->fx_rate_minor)->toBe(780000)
        ->and($this->asTenant('acme', fn () => ExchangeRate::query()->whereNull('effective_to')->sole()->rate_minor))->toBe(770000);

    $ssp = $this->asTenant('acme', fn () => Currency::query()->where('code', 'SSP')->sole());
    $this->put(at("currencies/{$ssp->id}"), [
        'name' => 'South Sudanese Pound',
        'symbol' => 'SSP',
        'decimals' => 0,
        'active' => 1,
    ])->assertSessionHasErrors('decimals');
    $this->put(at("currencies/{$ssp->id}"), [
        'name' => 'South Sudanese Pound',
        'symbol' => 'SSP',
        'decimals' => 2,
        'active' => 0,
    ])->assertSessionHasErrors('active');

    $kes = $this->asTenant('acme', fn () => Currency::query()->where('code', 'KES')->sole());
    $this->put(at("currencies/{$kes->id}"), [
        'name' => 'Kenyan Shilling',
        'symbol' => 'KSh',
        'decimals' => 2,
        'active' => 0,
    ])->assertSessionHasNoErrors();
    expect($deposit->fresh()->currency)->toBe('KES')
        ->and($deposit->fresh()->amount_minor)->toBe(500);

    $this->actingAsMember('acme', petrolPermissions(), user: $seller, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('cash/remittances'), [
        'attendant_id' => $yard['attendant'],
        'cashier_id' => $cashier->id,
        'amount' => '1.00',
        'currency' => 'KES',
    ])->assertSessionHasErrors('currency');

    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->get(at('overview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.operations.custodyCurrencies', function ($rows) {
                $codes = collect($rows)->pluck('currency');
                $usd = collect($rows)->firstWhere('currency', 'USD');

                return $codes->contains('KES')
                    && $codes->contains('SSP')
                    && $usd['cashier'] === 150
                    && $usd['attendant'] === 50;
            })
            ->where('overview.operations.custodyEquivalent.complete', false)
            ->where('overview.operations.custodyEquivalent.label', fn (string $label) => str_contains($label, 'does not rewrite')));

    $this->actingAsAppMember('acme', [Permissions::CASH_SUBMIT], user: $seller, branch: $this->juba);
    $this->withHeader('X-Branch-Id', $this->juba)->getJson(at('api/v1/outbox/kit'))
        ->assertOk()
        ->assertJsonPath('currencies.0.code', 'SSP');
    $rejected = (string) Str::uuid();
    $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), [
        'id' => $rejected,
        'kind' => 'cash.remit',
        'payload' => [
            'attendant_id' => $yard['attendant'],
            'cashier_id' => $cashier->id,
            'amount' => '1.00',
            'currency' => 'KES',
        ],
    ])->assertStatus(422);
    expect($this->asTenant('acme', fn () => AppSubmission::query()->whereKey($rejected)->exists()))->toBeFalse();

    $id = (string) Str::uuid();
    $body = [
        'id' => $id,
        'kind' => 'cash.remit',
        'payload' => [
            'attendant_id' => $yard['attendant'],
            'cashier_id' => $cashier->id,
            'amount' => '0.50',
            'currency' => 'USD',
        ],
    ];
    $first = $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), $body)->assertOk();
    $again = $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), $body)->assertOk();
    expect($again->json('transfer_id'))->toBe($first->json('transfer_id'))
        ->and($again->json('status'))->toBe(CashTransfer::PENDING)
        ->and($this->asTenant('acme', fn () => CashTransfer::query()->where('kind', CashTransfer::REMITTANCE)->count()))->toBe(2)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->attendantHeld($yard['shift'], $yard['attendant'], 'USD')))->toBe(50);
});

it('hands a nozzle over without moving cash or closing the shift', function () {
    $yard = forecourt();
    $outgoing = $this->platformUser(['name' => 'John']);
    $incoming = $this->platformUser(['name' => 'Peter']);
    $supervisor = $this->platformUser(['name' => 'Supervisor']);
    $this->asTenant('acme', fn () => Attendant::query()->whereKey($yard['attendant'])->update(['user_id' => $outgoing->id]));
    $this->post(at('attendants'), [
        'name' => 'Peter',
        'employee_code' => 'P2',
        'active' => 1,
        'at_station' => 1,
    ])->assertSessionHasNoErrors();
    $peter = $this->asTenant('acme', fn () => Attendant::query()->where('employee_code', 'P2')->sole());
    $this->asTenant('acme', fn () => $peter->update(['user_id' => $incoming->id]));

    $this->actingAsMember('acme', petrolPermissions(), user: $outgoing, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'attendant',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '10.00']],
    ])->assertSessionHasNoErrors();
    $this->post(at('cash/remittances'), [
        'attendant_id' => $yard['attendant'],
        'cashier_id' => $supervisor->id,
        'amount' => '4.00',
        'currency' => 'SSP',
    ])->assertSessionHasNoErrors();

    $assignment = $this->asTenant('acme', fn () => ShiftAssignment::query()->sole());
    $this->post(at('handovers'), [
        'assignment_id' => $assignment->id,
        'incoming_attendant_id' => $peter->id,
        'closing' => '1001',
        'opening' => '1001',
    ])->assertSessionHasNoErrors();
    $handover = $this->asTenant('acme', fn () => AttendantHandover::query()->sole());
    expect($handover->status)->toBe(AttendantHandover::REQUESTED)
        ->and($this->asTenant('acme', fn () => Shift::query()->whereKey($yard['shift'])->value('status')))->toBe(Shift::OPEN)
        ->and($this->asTenant('acme', fn () => CashTransfer::query()->where('source_id', $yard['attendant'])->sole()->status))->toBe(CashTransfer::PENDING);

    $this->actingAsMember('acme', petrolPermissions(), user: $incoming, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("handovers/{$handover->id}/confirm"))->assertSessionHasNoErrors();
    $this->actingAsMember('acme', petrolPermissions(), user: $supervisor, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("handovers/{$handover->id}/approve"))->assertSessionHasNoErrors();

    $stored = $this->asTenant('acme', fn () => AttendantHandover::query()->sole());
    $closed = $this->asTenant('acme', fn () => ShiftAssignment::query()->whereKey($assignment->id)->sole());
    $opened = $this->asTenant('acme', fn () => ShiftAssignment::query()->whereKey($stored->incoming_assignment_id)->sole());
    expect($stored->status)->toBe(AttendantHandover::APPROVED)
        ->and($closed->status)->toBe(ShiftAssignment::CLOSED)
        ->and($closed->attendant_id)->toBe($yard['attendant'])
        ->and($opened->attendant_id)->toBe($peter->id)
        ->and($opened->isActive())->toBeTrue()
        ->and($opened->opening_milli)->toBe($closed->closing_milli)
        ->and($this->asTenant('acme', fn () => Shift::query()->whereKey($yard['shift'])->value('status')))->toBe(Shift::OPEN)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->sole()->attendant_id))->toBe($yard['attendant'])
        ->and($this->asTenant('acme', fn () => app(Custody::class)->attendantHeld($yard['shift'], $yard['attendant'], 'SSP')))->toBe(1000)
        ->and($this->asTenant('acme', fn () => app(Custody::class)->attendantHeld($yard['shift'], $peter->id, 'SSP')))->toBe(0);

    $this->actingAsMember('acme', petrolPermissions(), user: $incoming, branch: $this->juba);
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $peter->id,
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '10.00']],
    ])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => FuelSale::query()->where('attendant_id', $peter->id)->sole()->assignment_id))->toBe($opened->id);
});

it('reassigns, allows an early departure, and keeps each pump period', function () {
    $yard = forecourt();
    $supervisor = auth()->user();
    $this->post(at('attendants'), [
        'name' => 'Peter',
        'employee_code' => 'P2',
        'active' => 1,
        'at_station' => 1,
    ])->assertSessionHasNoErrors();
    $peter = $this->asTenant('acme', fn () => Attendant::query()->where('employee_code', 'P2')->sole());
    $pump = $this->asTenant('acme', fn () => Pump::query()->where('code', 'P1')->sole());
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->post(at("pumps/{$pump->id}/nozzles"), [
        'fuel_product_id' => $product->id,
        'name' => 'Nozzle 2',
        'code' => 'N2',
    ])->assertSessionHasNoErrors();
    $second = $this->asTenant('acme', fn () => Nozzle::query()->where('code', 'N2')->sole());
    $first = $this->asTenant('acme', fn () => ShiftAssignment::query()->sole());
    $this->post(at("shifts/{$yard['shift']}/assignments"), [
        'attendant_id' => $peter->id,
        'nozzle_id' => $second->id,
    ])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => ShiftAssignment::query()->where('status', ShiftAssignment::ACTIVE)->count()))->toBe(2);

    $this->post(at('handovers/reassign'), [
        'assignment_id' => $first->id,
        'incoming_attendant_id' => $peter->id,
        'closing' => '1000',
        'opening' => '1000',
    ])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => ShiftAssignment::query()->whereKey($first->id)->value('status')))->toBe(ShiftAssignment::CLOSED)
        ->and($this->asTenant('acme', fn () => ShiftAssignment::query()->where('nozzle_id', $yard['nozzle'])->where('status', ShiftAssignment::ACTIVE)->sole()->attendant_id))->toBe($peter->id)
        ->and($this->asTenant('acme', fn () => Shift::query()->whereKey($yard['shift'])->value('status')))->toBe(Shift::OPEN);

    $cover = $this->asTenant('acme', fn () => ShiftAssignment::query()->where('nozzle_id', $second->id)->where('status', ShiftAssignment::ACTIVE)->sole());
    $this->post(at('handovers/depart'), [
        'assignment_id' => $cover->id,
        'closing' => '1000',
    ])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => ShiftAssignment::query()->whereKey($cover->id)->value('status')))->toBe(ShiftAssignment::CLOSED)
        ->and($this->asTenant('acme', fn () => Shift::query()->whereKey($yard['shift'])->where('status', Shift::OPEN)->exists()))->toBeTrue()
        ->and($supervisor->id)->not->toBe('');
});

it('records a break without giving the gap to the absent attendant', function () {
    $yard = forecourt();
    $this->post(at('attendants'), [
        'name' => 'Peter',
        'employee_code' => 'P2',
        'active' => 1,
        'at_station' => 1,
    ])->assertSessionHasNoErrors();
    $peter = $this->asTenant('acme', fn () => Attendant::query()->where('employee_code', 'P2')->sole());
    $assignment = $this->asTenant('acme', fn () => ShiftAssignment::query()->sole());
    $this->post(at('handovers/breaks'), [
        'assignment_id' => $assignment->id,
        'reading' => '1000',
        'reason' => 'Meal',
        'replacement_id' => $peter->id,
    ])->assertSessionHasNoErrors();
    $break = $this->asTenant('acme', fn () => ShiftBreak::query()->sole());
    expect($this->asTenant('acme', fn () => ShiftAssignment::query()->whereKey($assignment->id)->value('status')))->toBe(ShiftAssignment::CLOSED)
        ->and($this->asTenant('acme', fn () => ShiftAssignment::query()->whereKey($break->replacement_assignment_id)->value('attendant_id')))->toBe($peter->id);

    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '10.00']],
    ])->assertSessionHasErrors('attendant_id');
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $peter->id,
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '10.00']],
    ])->assertSessionHasNoErrors();

    $this->post(at("handovers/breaks/{$break->id}/end"), ['reading' => '1001'])->assertSessionHasNoErrors();
    $returned = $this->asTenant('acme', fn () => ShiftAssignment::query()->whereKey($break->fresh()->return_assignment_id)->sole());
    expect($returned->attendant_id)->toBe($yard['attendant'])
        ->and($returned->isActive())->toBeTrue()
        ->and($this->asTenant('acme', fn () => FuelSale::query()->sole()->attendant_id))->toBe($peter->id);
});

it('keeps shared-pump sales with the attendant who made them', function () {
    $yard = forecourt();
    $this->asTenant('acme', fn () => Station::query()->where('branch_id', $this->juba)->update(['shared_pumps' => true]));
    $this->post(at('attendants'), [
        'name' => 'Peter',
        'employee_code' => 'P2',
        'active' => 1,
        'at_station' => 1,
    ])->assertSessionHasNoErrors();
    $peter = $this->asTenant('acme', fn () => Attendant::query()->where('employee_code', 'P2')->sole());
    $this->post(at("shifts/{$yard['shift']}/assignments"), [
        'attendant_id' => $peter->id,
        'nozzle_id' => $yard['nozzle'],
    ])->assertSessionHasNoErrors();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '10.00']],
    ])->assertSessionHasNoErrors();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '2',
        'attendant_id' => $peter->id,
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '20.00']],
    ])->assertSessionHasNoErrors();
    $sales = $this->asTenant('acme', fn () => FuelSale::query()->get());
    expect($sales)->toHaveCount(2)
        ->and($sales->firstWhere('attendant_id', $yard['attendant'])->quantity_milli)->toBe(1000)
        ->and($sales->firstWhere('attendant_id', $peter->id)->quantity_milli)->toBe(2000)
        ->and($sales->pluck('assignment_id')->unique())->toHaveCount(2);
});

it('refuses an overlapping nozzle assignment and a mismatched reading without a meter event', function () {
    $yard = forecourt();
    $this->post(at('attendants'), [
        'name' => 'Peter',
        'employee_code' => 'P2',
        'active' => 1,
        'at_station' => 1,
    ])->assertSessionHasNoErrors();
    $peter = $this->asTenant('acme', fn () => Attendant::query()->where('employee_code', 'P2')->sole());
    $this->post(at("shifts/{$yard['shift']}/assignments"), [
        'attendant_id' => $peter->id,
        'nozzle_id' => $yard['nozzle'],
    ])->assertSessionHasErrors('nozzle_id');

    $assignment = $this->asTenant('acme', fn () => ShiftAssignment::query()->sole());
    $this->post(at('handovers'), [
        'assignment_id' => $assignment->id,
        'incoming_attendant_id' => $peter->id,
        'closing' => '1000',
        'opening' => '0',
    ])->assertSessionHasErrors('opening');
    $this->post(at('handovers'), [
        'assignment_id' => $assignment->id,
        'incoming_attendant_id' => $peter->id,
        'closing' => '900',
        'opening' => '900',
    ])->assertSessionHasErrors('closing');
    $this->post(at('handovers'), [
        'assignment_id' => $assignment->id,
        'incoming_attendant_id' => $peter->id,
        'closing' => '1000',
        'opening' => '0',
        'event_reason' => 'Meter replaced',
    ])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => AttendantHandover::query()->sole()->event_reason))->toBe('Meter replaced')
        ->and($this->asTenant('acme', fn () => ShiftAssignment::query()->whereKey($assignment->id)->value('status')))->toBe(ShiftAssignment::ACTIVE);
});

it('keeps a critical exception through an approved closing and blocks a silent approval', function () {
    $yard = forecourt();
    $approver = $this->platformUser(['name' => 'Closer']);
    $shift = $this->asTenant('acme', fn () => Shift::query()->whereKey($yard['shift'])->firstOrFail());
    $this->asTenant('acme', fn () => app(ShiftExceptions::class)->open(
        $shift,
        null,
        null,
        'equipment',
        'Pump stopped',
        ShiftException::CRITICAL,
        auth()->user(),
    ));
    $this->post(at("shifts/{$yard['shift']}/submit"), [
        'counted_drawer' => '100.00',
        'closings' => [['nozzle_id' => $yard['nozzle'], 'reading' => '1000']],
        'counts' => [],
    ])->assertSessionHasNoErrors();
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("shifts/{$yard['shift']}/review"))->assertSessionHasNoErrors();
    $this->post(at("shifts/{$yard['shift']}/approve"))->assertSessionHasErrors('exception_note');
    $this->post(at("shifts/{$yard['shift']}/approve"), ['exception_note' => 'Pump was repaired and the reading stands.'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => ShiftException::query()->sole()->status))->toBe(ShiftException::OPEN)
        ->and($this->asTenant('acme', fn () => Shift::query()->whereKey($yard['shift'])->value('status')))->toBe(Shift::APPROVED);
});

it('does not take a handover from another branch or from someone without the permission', function () {
    $yard = forecourt();
    $assignment = $this->asTenant('acme', fn () => ShiftAssignment::query()->sole());
    $this->post(at('attendants'), [
        'name' => 'Peter',
        'employee_code' => 'P2',
        'active' => 1,
        'at_station' => 1,
    ])->assertSessionHasNoErrors();
    $peter = $this->asTenant('acme', fn () => Attendant::query()->where('employee_code', 'P2')->sole());
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->wau, 'WAU');
    $this->post(at('handovers'), [
        'assignment_id' => $assignment->id,
        'incoming_attendant_id' => $peter->id,
        'closing' => '1000',
        'opening' => '1000',
    ])->assertNotFound();

    $this->actingAsMember('acme', [Permissions::SALES_VIEW], branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('handovers'), [
        'assignment_id' => $assignment->id,
        'incoming_attendant_id' => $peter->id,
        'closing' => '1000',
        'opening' => '1000',
    ])->assertForbidden();
});

it('does not accept an offline attendant handover', function () {
    $yard = forecourt();
    $this->actingAsAppMember('acme', petrolPermissions(), branch: $this->juba);
    $id = (string) Str::uuid();
    $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), [
        'id' => $id,
        'kind' => 'attendant.handover',
        'payload' => ['assignment_id' => $yard['shift']],
    ])->assertStatus(422);
    expect($this->asTenant('acme', fn () => AppSubmission::query()->whereKey($id)->exists()))->toBeFalse()
        ->and($this->asTenant('acme', fn () => AttendantHandover::query()->count()))->toBe(0);
});

it('keeps a shift exception on the record through review, resolution, close, and reopen', function () {
    $yard = forecourt();
    $this->post(at('exceptions'), [
        'shift_id' => $yard['shift'],
        'type' => 'equipment',
        'description' => 'Nozzle leaked',
        'severity' => 'warning',
    ])->assertSessionHasNoErrors();
    $id = $this->asTenant('acme', fn () => ShiftException::query()->sole()->id);
    $this->post(at("exceptions/{$id}/review"))->assertSessionHasNoErrors();
    $this->post(at("exceptions/{$id}/resolve"), ['resolution' => 'Replaced the seal'])->assertSessionHasNoErrors();
    $this->post(at("exceptions/{$id}/close"))->assertSessionHasNoErrors();
    $this->post(at("exceptions/{$id}/reopen"), ['reason' => 'The leak returned'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => ShiftException::query()->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => ShiftException::query()->whereKey($id)->value('status')))->toBe(ShiftException::OPEN);
});

it('converts a dip with the chart version that was current and does not move stock', function () {
    $yard = forecourt();
    $this->post(at('tanks'), [
        'fuel_product_id' => $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->value('id')),
        'name' => 'Too full',
        'code' => 'T9',
        'capacity' => '1000',
        'safe_fill' => '2000',
        'status' => 'active',
    ])->assertSessionHasErrors('safe_fill');

    $this->post(at("tanks/{$yard['tank']}/chart"), [
        'reference' => 'JUBA-T1',
        'effective_from' => '2026-11-01',
        'csv' => "depth_mm,litres\n0,0\n1000,10000\n",
    ])->assertSessionHasNoErrors();
    $this->post(at("tanks/{$yard['tank']}/chart"), [
        'reference' => 'bad',
        'effective_from' => '2026-11-01',
        'csv' => "0,100\n100,50\n",
    ])->assertSessionHasErrors('points');
    $chart = $this->asTenant('acme', fn () => TankChart::query()->where('status', TankChart::ACTIVE)->sole());

    $this->post(at("tanks/{$yard['tank']}/measure"), [
        'method' => 'dipstick',
        'depth_mm' => 500,
        'water_mm' => 0,
    ])->assertSessionHasNoErrors();
    $this->post(at("tanks/{$yard['tank']}/measure"), [
        'method' => 'dipstick',
        'depth_mm' => 5000,
    ])->assertSessionHasErrors('depth');
    $reading = $this->asTenant('acme', fn () => TankMeasurement::query()->sole());
    expect($reading->measured_milli)->toBe(5_000_000)
        ->and($reading->chart_id)->toBe($chart->id)
        ->and($reading->book_milli)->toBe(5_000_000)
        ->and($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->quantity_milli))->toBe(5_000_000)
        ->and($this->asTenant('acme', fn () => StockMovement::query()->where('kind', StockMovement::COUNT)->count()))->toBe(0);

    $this->post(at("tanks/{$yard['tank']}/chart"), [
        'reference' => 'JUBA-T1-B',
        'effective_from' => '2026-11-09',
        'csv' => "0,0\n1000,8000\n",
    ])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => TankMeasurement::query()->whereKey($reading->id)->value('measured_milli')))->toBe(5_000_000)
        ->and($this->asTenant('acme', fn () => TankMeasurement::query()->whereKey($reading->id)->value('chart_id')))->toBe($chart->id)
        ->and($this->asTenant('acme', fn () => TankChart::query()->where('status', TankChart::RETIRED)->count()))->toBe(1);
});

it('holds a delivery until approval and subtracts fuel sold while the tanker unloads', function () {
    $yard = forecourt();
    $approver = $this->platformUser(['name' => 'Receiver']);
    $this->post(at('suppliers'), ['name' => 'Nile Fuel'])->assertSessionHasNoErrors();
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->sole());
    $this->travel(1)->minutes();
    $this->post(at('settings/station'), [
        'code' => 'JUBA',
        'collection_mode' => 'both',
        'low_stock_percent' => 15,
        'active' => 1,
        'dispense_during_receiving' => 0,
    ])->assertSessionHasNoErrors();
    $this->post(at('deliveries'), [
        'supplier_id' => $supplier->id,
        'tank_id' => $yard['tank'],
        'declared' => '1000',
        'before' => '5000',
        'unit_cost' => '8.00',
        'supplier_reference' => 'DEL-1',
    ])->assertSessionHasNoErrors();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '10.00']],
    ])->assertSessionHasErrors('nozzle_id');
    $this->post(at('settings/station'), [
        'code' => 'JUBA',
        'collection_mode' => 'both',
        'low_stock_percent' => 15,
        'active' => 1,
        'dispense_during_receiving' => 1,
    ])->assertSessionHasNoErrors();
    $this->post(at('deliveries'), [
        'supplier_id' => $supplier->id,
        'tank_id' => $yard['tank'],
        'declared' => '1000',
        'before' => '5000',
        'unit_cost' => '8.00',
        'supplier_reference' => 'DEL-2',
    ])->assertSessionHasErrors('tank_id');
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '500',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '5000.00']],
    ])->assertRedirect();
    $receipt = $this->asTenant('acme', fn () => FuelReceipt::query()->sole());
    $this->post(at("deliveries/{$receipt->id}/complete"), [
        'after' => '5500',
        'accepted' => '1000',
    ])->assertSessionHasNoErrors();
    $checked = $this->asTenant('acme', fn () => FuelReceipt::query()->whereKey($receipt->id)->sole());
    expect($checked->declared_milli)->toBe(1_000_000)
        ->and($checked->accepted_milli)->toBe(1_000_000)
        ->and($checked->dispensed_milli)->toBe(500_000)
        ->and($checked->expected_milli)->toBe(5_500_000)
        ->and($checked->receiving_variance_milli)->toBe(0)
        ->and($checked->status)->toBe(FuelReceipt::PENDING)
        ->and($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->quantity_milli))->toBe(4_500_000);

    $this->post(at("deliveries/{$receipt->id}/approve"))->assertSessionHasErrors('receipt');
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("deliveries/{$receipt->id}/approve"))->assertSessionHasNoErrors();
    $this->post(at("deliveries/{$receipt->id}/approve"))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->quantity_milli))->toBe(5_500_000)
        ->and($this->asTenant('acme', fn () => StockMovement::query()->where('kind', StockMovement::RECEIPT)->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => FuelReceipt::query()->whereKey($receipt->id)->value('status')))->toBe(FuelReceipt::APPROVED);

    $this->post(at('deliveries'), [
        'supplier_id' => $supplier->id,
        'tank_id' => $yard['tank'],
        'declared' => '100',
        'before' => '5500',
        'unit_cost' => '8.00',
        'supplier_reference' => 'DEL-1',
    ])->assertSessionHasErrors('supplier_reference');
});

it('keeps a delivery provisional when the closing dip is missing', function () {
    $yard = forecourt();
    $this->post(at('suppliers'), ['name' => 'Nile Fuel'])->assertSessionHasNoErrors();
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->sole());
    $this->post(at('deliveries'), [
        'supplier_id' => $supplier->id,
        'tank_id' => $yard['tank'],
        'declared' => '100',
        'before' => '5000',
        'unit_cost' => '8.00',
    ])->assertSessionHasNoErrors();
    $receipt = $this->asTenant('acme', fn () => FuelReceipt::query()->sole());
    $this->post(at("deliveries/{$receipt->id}/complete"), ['accepted' => '100'])->assertSessionHasNoErrors();
    $approver = $this->platformUser(['name' => 'Receiver']);
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("deliveries/{$receipt->id}/approve"))->assertSessionHasErrors('receipt');
    expect($this->asTenant('acme', fn () => FuelReceipt::query()->whereKey($receipt->id)->value('reconciliation')))->toBe('provisional')
        ->and($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->quantity_milli))->toBe(5_000_000);
});

it('flags water from a dip and keeps test fuel out of customer sales', function () {
    $yard = forecourt();
    $approver = $this->platformUser(['name' => 'Supervisor']);
    $this->post(at('settings/station'), [
        'code' => 'JUBA',
        'collection_mode' => 'both',
        'low_stock_percent' => 15,
        'active' => 1,
        'water_alert_mm' => 10,
    ])->assertSessionHasNoErrors();
    $this->post(at("tanks/{$yard['tank']}/chart"), [
        'reference' => 'JUBA-T1',
        'effective_from' => '2026-11-01',
        'csv' => "0,0\n1000,10000\n",
    ])->assertSessionHasNoErrors();
    $this->post(at("tanks/{$yard['tank']}/measure"), [
        'method' => 'dipstick',
        'depth_mm' => 500,
        'water_mm' => 15,
    ])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->water_alert))->toBeTrue()
        ->and($this->asTenant('acme', fn () => ShiftException::query()->where('type', 'water')->count()))->toBe(1);
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '1',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '10.00']],
    ])->assertSessionHasErrors('nozzle_id');
    $this->post(at("tanks/{$yard['tank']}/water"), ['note' => 'Water drained'])->assertSessionHasNoErrors();
    $this->post(at('calibration/draws'), [
        'nozzle_id' => $yard['nozzle'],
        'tank_id' => $yard['tank'],
        'litres' => '20',
    ])->assertSessionHasNoErrors();
    $draw = $this->asTenant('acme', fn () => FuelDraw::query()->sole());
    $this->actingAsMember('acme', petrolPermissions(), user: $approver, branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("calibration/draws/{$draw->id}/approve"))->assertSessionHasNoErrors();
    $this->post(at("calibration/draws/{$draw->id}/approve"))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->quantity_milli))->toBe(4_980_000)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(0)
        ->and($this->asTenant('acme', fn () => StockMovement::query()->where('kind', StockMovement::TEST)->count()))->toBe(1);
    $this->post(at("calibration/draws/{$draw->id}/return"))->assertSessionHasNoErrors();
    $this->post(at("calibration/draws/{$draw->id}/return"))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => Tank::query()->findOrFail($yard['tank'])->quantity_milli))->toBe(5_000_000)
        ->and($this->asTenant('acme', fn () => FuelDraw::query()->whereKey($draw->id)->value('status')))->toBe(FuelDraw::RETURNED);
});

it('lets a replaced meter restart without rewriting sales', function () {
    $yard = forecourt();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '10',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '100.00']],
    ])->assertRedirect();
    $sale = $this->asTenant('acme', fn () => FuelSale::query()->sole()->quantity_milli);
    $this->post(at('calibration/events'), [
        'nozzle_id' => $yard['nozzle'],
        'type' => 'rollover',
        'previous' => '2000',
        'next' => '0',
        'technician' => 'Deng',
    ])->assertSessionHasNoErrors();
    $this->post(at("shifts/{$yard['shift']}/submit"), [
        'counted_drawer' => '200.00',
        'closings' => [['nozzle_id' => $yard['nozzle'], 'reading' => '10']],
    ])->assertSessionHasNoErrors();
    $detail = $this->asTenant('acme', fn () => app(Reconciliation::class)->detail(Shift::query()->findOrFail($yard['shift'])));
    expect($detail['nozzles'][0]['dispensed'])->toBe(1_010_000)
        ->and($detail['nozzles'][0]['sold'])->toBe(10_000)
        ->and($detail['nozzles'][0]['variance'])->toBe(1_000_000)
        ->and($this->asTenant('acme', fn () => FuelSale::query()->sole()->quantity_milli))->toBe($sale)
        ->and($this->asTenant('acme', fn () => NozzleEvent::query()->count()))->toBe(1);
});

it('does not take a tank chart from another branch or from someone without the permission', function () {
    $yard = forecourt();
    $foreign = $this->asTenant('acme', fn () => Tank::query()->create([
        'branch_id' => $this->wau,
        'fuel_product_id' => FuelProduct::query()->where('code', 'PMS')->sole()->id,
        'name' => 'Wau tank',
        'code' => 'W1',
        'capacity_milli' => 1_000_000,
        'status' => Tank::ACTIVE,
    ]));
    $this->post(at("tanks/{$foreign->id}/chart"), [
        'reference' => 'WAU',
        'effective_from' => '2026-11-01',
        'csv' => "0,0\n100,100\n",
    ])->assertNotFound();
    $this->actingAsMember('acme', [Permissions::SALES_VIEW], branch: $this->juba);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("tanks/{$yard['tank']}/chart"), [
        'reference' => 'JUBA',
        'effective_from' => '2026-11-01',
        'csv' => "0,0\n100,100\n",
    ])->assertForbidden();
});

it('does not accept an offline tank measurement', function () {
    forecourt();
    $this->actingAsAppMember('acme', petrolPermissions(), branch: $this->juba);
    $id = (string) Str::uuid();
    $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), [
        'id' => $id,
        'kind' => 'tank.measure',
        'payload' => ['litres' => '1'],
    ])->assertStatus(422);
    expect($this->asTenant('acme', fn () => AppSubmission::query()->whereKey($id)->exists()))->toBeFalse()
        ->and($this->asTenant('acme', fn () => TankMeasurement::query()->count()))->toBe(0);
});

function openStation(string $branchId, string $code): void
{
    test()->put(at('branch'), ['branch_id' => $branchId])->assertRedirect();
    test()->post(at('settings/station'), [
        'code' => $code,
        'collection_mode' => 'both',
        'low_stock_percent' => 15,
        'active' => 1,
    ])->assertSessionHasNoErrors();
}

it('lets only the sending station dispatch fuel and only the receiving station receive it', function () {
    $yard = forecourt();
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->wau, 'WAU');
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->post(at('tanks'), [
        'fuel_product_id' => $product->id,
        'name' => 'Wau tank',
        'code' => 'TW',
        'capacity' => '20000',
        'status' => 'active',
    ])->assertSessionHasNoErrors();
    $destination = $this->asTenant('acme', fn () => Tank::query()->where('code', 'TW')->sole());
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('transfers/fuel'), [
        'source_tank_id' => $yard['tank'],
        'destination_tank_id' => $destination->id,
        'litres' => '100',
    ])->assertSessionHasNoErrors();
    $transfer = $this->asTenant('acme', fn () => FuelTransfer::query()->sole());

    $this->put(at('branch'), ['branch_id' => $this->wau])->assertRedirect();
    $this->post(at("transfers/fuel/{$transfer->id}/approve"))->assertNotFound();

    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("transfers/fuel/{$transfer->id}/approve"))->assertSessionHasNoErrors();

    $this->put(at('branch'), ['branch_id' => $this->wau])->assertRedirect();
    $this->post(at("transfers/fuel/{$transfer->id}/dispatch"), ['litres' => '100'])->assertNotFound();

    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("transfers/fuel/{$transfer->id}/dispatch"), ['litres' => '100'])->assertSessionHasNoErrors();
    $this->post(at("transfers/fuel/{$transfer->id}/receive"), ['litres' => '5000'])->assertNotFound();

    expect($this->asTenant('acme', fn () => FuelTransfer::query()->findOrFail($transfer->id)->status))->toBe(FuelTransfer::DISPATCHED)
        ->and($this->asTenant('acme', fn () => Tank::query()->findOrFail($destination->id)->quantity_milli))->toBe(0);
});

it('keeps store transfer steps on the station that owns each store', function () {
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    openStation($this->juba, 'JUBA');
    openStation($this->wau, 'WAU');
    $this->post(at('store/stores'), ['name' => 'Wau shop', 'code' => 'SW'])->assertSessionHasNoErrors();
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('store/stores'), ['name' => 'Juba shop', 'code' => 'SJ'])->assertSessionHasNoErrors();
    $this->post(at('store/products'), ['name' => 'Oil filter', 'code' => 'OF1', 'unit' => 'piece'])->assertSessionHasNoErrors();
    $juba = $this->asTenant('acme', fn () => Store::query()->where('code', 'SJ')->sole());
    $wau = $this->asTenant('acme', fn () => Store::query()->where('code', 'SW')->sole());
    $product = $this->asTenant('acme', fn () => StoreProduct::query()->sole());
    $this->post(at('store/receive'), [
        'store_id' => $juba->id,
        'product_id' => $product->id,
        'quantity' => '10',
        'cost' => '5.00',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
    $this->post(at('transfers/store'), [
        'source_store_id' => $juba->id,
        'destination_store_id' => $wau->id,
        'product_id' => $product->id,
        'quantity' => '4',
    ])->assertSessionHasNoErrors();
    $transfer = $this->asTenant('acme', fn () => StoreTransfer::query()->sole());

    $this->put(at('branch'), ['branch_id' => $this->wau])->assertRedirect();
    $this->post(at("transfers/store/{$transfer->id}/approve"))->assertNotFound();
    $this->post(at("transfers/store/{$transfer->id}/dispatch"), ['quantity' => '4'])->assertNotFound();

    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at("transfers/store/{$transfer->id}/approve"))->assertSessionHasNoErrors();
    $this->post(at("transfers/store/{$transfer->id}/dispatch"), ['quantity' => '4'])->assertSessionHasNoErrors();
    $this->post(at("transfers/store/{$transfer->id}/receive"), ['quantity' => '4'])->assertNotFound();

    $this->put(at('branch'), ['branch_id' => $this->wau])->assertRedirect();
    $this->post(at("transfers/store/{$transfer->id}/receive"), ['quantity' => '4'])->assertSessionHasNoErrors();
    $this->post(at("transfers/store/{$transfer->id}/complete"))->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => StoreTransfer::query()->findOrFail($transfer->id)->status))->toBe(StoreTransfer::COMPLETED);
});

it('takes payments in the currency of the price and voids in the books currency', function () {
    $yard = forecourt();
    $product = $this->asTenant('acme', fn () => FuelProduct::query()->where('code', 'PMS')->sole());
    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $this->put(at('branch'), ['branch_id' => $this->juba])->assertRedirect();
    $this->post(at('currencies'), ['name' => 'Ugandan Shilling', 'code' => 'UGX', 'symbol' => 'USh', 'decimals' => 0])->assertSessionHasNoErrors();
    $this->put(at('currencies-station'), ['enabled' => ['SSP', 'UGX']])->assertSessionHasNoErrors();
    $this->post(at('rates'), ['base_currency' => 'UGX', 'quote_currency' => 'SSP', 'rate' => '2.00'])->assertSessionHasNoErrors();
    $this->post(at('settings/prices'), [
        'fuel_product_id' => $product->id,
        'selling_price' => '5000',
        'currency' => 'UGX',
    ])->assertSessionHasNoErrors();

    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '10',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '50000']],
    ])->assertSessionHasNoErrors();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'amount' => '10000.50',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '10000']],
    ])->assertSessionHasErrors('litres');

    $sale = $this->asTenant('acme', fn () => FuelSale::query()->sole());
    expect($sale->currency)->toBe('UGX')
        ->and($sale->total_minor)->toBe(50000)
        ->and($sale->reporting_total_minor)->toBe(10_000_000);

    $this->post(at("sales/{$sale->id}/void"), ['reason' => 'Wrong grade'])->assertSessionHasNoErrors();
    $void = collect($this->platform()->moduleEvents['finance'] ?? [])
        ->map(fn (array $event) => Envelope::fromArray($event))
        ->first(fn (Envelope $event) => $event->eventType === 'finance.invoice.voided');
    expect($void->payload['currency'])->toBe('SSP')
        ->and($void->payload['total_minor'])->toBe(10_000_000)
        ->and($void->payload['refund']['amount_minor'])->toBe(10_000_000);
});

it('refuses the same payment method twice in one sale', function () {
    $yard = forecourt();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '10',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [
            ['method_id' => $yard['cash'], 'amount' => '60.00'],
            ['method_id' => $yard['cash'], 'amount' => '40.00'],
        ],
    ])->assertSessionHasErrors('payments');

    expect($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(0);
});

it('answers a malformed offline submission with 422 and keeps results to their sender', function () {
    $yard = forecourt();
    $this->actingAsAppMember('acme', petrolPermissions(), branch: $this->juba);
    $post = fn (array $body) => $this->withHeader('X-Branch-Id', $this->juba)->postJson(at('api/v1/outbox'), $body);
    $sale = fn (array $override) => [
        'id' => (string) Str::uuid(),
        'kind' => 'sale.record',
        'payload' => $override + [
            'nozzle_id' => $yard['nozzle'],
            'litres' => '10',
            'attendant_id' => $yard['attendant'],
            'collected_by' => 'cashier',
            'payments' => [['method_id' => $yard['cash'], 'amount' => '100.00']],
        ],
    ];

    $post($sale(['nozzle_id' => ['not', 'a', 'string']]))->assertStatus(422);
    $post($sale(['payments' => ['cash']]))->assertStatus(422);
    $post([
        'id' => (string) Str::uuid(),
        'kind' => 'cash.remit',
        'payload' => ['attendant_id' => $yard['attendant'], 'cashier_id' => (string) Str::uuid(), 'amount' => 'lots'],
    ])->assertStatus(422)->assertJsonValidationErrors('amount');

    $body = $sale([]);
    $post($body)->assertOk();

    $this->actingAsAppMember('acme', petrolPermissions(), branch: $this->juba);
    $post($body)->assertStatus(409);

    expect($this->asTenant('acme', fn () => FuelSale::query()->count()))->toBe(1);
});

it('validates the water alert and saves the calibration interval', function () {
    forecourt();
    $station = fn (array $extra) => $this->post(at('settings/station'), [
        'code' => 'JUBA',
        'collection_mode' => 'both',
        'low_stock_percent' => 15,
        'active' => 1,
    ] + $extra);

    $station(['water_alert_mm' => 'abc'])->assertSessionHasErrors('water_alert_mm');
    $station(['water_alert_mm' => '-5'])->assertSessionHasErrors('water_alert_mm');
    $station(['calibration_interval_days' => '0'])->assertSessionHasErrors('calibration_interval_days');
    $station(['water_alert_mm' => '40', 'calibration_interval_days' => '90'])->assertSessionHasNoErrors();

    $saved = $this->asTenant('acme', fn () => Station::query()->where('branch_id', $this->juba)->sole());
    expect($saved->water_alert_mm)->toBe(40)
        ->and($saved->calibration_interval_days)->toBe(90);

    $station(['water_alert_mm' => '', 'calibration_interval_days' => ''])->assertSessionHasNoErrors();
    $cleared = $this->asTenant('acme', fn () => Station::query()->where('branch_id', $this->juba)->sole());
    expect($cleared->water_alert_mm)->toBeNull()
        ->and($cleared->calibration_interval_days)->toBeNull();
});

/*
| The mobile app: GET api/v1/me, then every web page as data at
| api/v1/app/<web path> (App\Support\AppPages), with the branch in X-Branch-Id.
*/

function onPhone(string $method, string $path, array $data = [], ?string $branch = null, ?string $from = null)
{
    $request = test()->withHeader('X-Branch-Id', $branch ?? test()->juba);
    if ($from !== null) {
        $request = $request->withHeader('X-App-Page', $from);
    }

    return $request->json($method, test()->workspaceUrl('acme', 'api/v1/app/'.ltrim($path, '/')), $data);
}

it('names the member, the workspace and the stations they work in', function () {
    $this->actingAsAppMember('acme', [Permissions::SALES_CREATE], branch: $this->juba);

    $this->getJson($this->workspaceUrl('acme', 'api/v1/me'))
        ->assertOk()
        ->assertJsonPath('organization.name', 'Acme')
        ->assertJsonPath('organization.currency', 'SSP')
        ->assertJsonCount(1, 'branches')
        ->assertJsonPath('branches.0.name', 'Juba')
        ->assertJsonPath('reason', null);
});

it('sells fuel from the phone through the web pages as data', function () {
    $yard = forecourt();
    $this->actingAsAppMember('acme', petrolPermissions(), branch: $this->juba);

    onPhone('GET', 'sales/create')
        ->assertOk()
        ->assertJsonPath('component', 'sales/create')
        ->assertJsonPath('props.branch.name', 'Juba')
        // What the phone needs to work out the total itself, and who may serve.
        ->assertJsonPath('props.collectionMode', 'both')
        ->assertJsonPath('props.nozzles.0.priceMinor', 1000)
        ->assertJsonPath('props.nozzles.0.currency', 'SSP')
        ->assertJsonPath('props.nozzles.0.attendantIds', [$yard['attendant']]);

    $made = onPhone('POST', 'sales', [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '20',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'idempotency_key' => (string) Str::uuid(),
        'payments' => [['method_id' => $yard['cash'], 'amount' => '200.00']],
    ], from: 'sales/create')->assertOk();

    $sale = $this->asTenant('acme', fn () => FuelSale::query()->sole());
    expect($made->json('redirect'))->toBe("sales/{$sale->id}")
        ->and($made->json('message'))->toBe("Sale {$sale->number} recorded.");

    onPhone('GET', "sales/{$sale->id}")->assertOk()->assertJsonPath('component', 'sales/show');
    onPhone('POST', 'sales', ['nozzle_id' => $yard['nozzle']], from: 'sales/create')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attendant_id');
});

it('opens phone pages only in a station open to the member, with its permissions there', function () {
    $this->actingAsAppMember('acme', [Permissions::SALES_VIEW], branch: $this->juba);

    $this->getJson($this->workspaceUrl('acme', 'api/v1/app/sales'))->assertStatus(409)->assertJsonPath('code', 'branch_required');
    onPhone('GET', 'sales', branch: $this->wau)->assertStatus(409);
    onPhone('GET', 'sales/create')->assertForbidden();
});

it('refuses phone calls without the app token', function () {
    $this->withHeader('X-Branch-Id', $this->juba)->getJson($this->workspaceUrl('acme', 'api/v1/app/sales'))->assertUnauthorized();
    $this->getJson($this->workspaceUrl('acme', 'api/v1/me'))->assertUnauthorized();
});

it('lists every station with its set-up and today, and a branch still to set up', function () {
    $yard = forecourt();
    $this->post(at('sales'), [
        'nozzle_id' => $yard['nozzle'],
        'litres' => '10',
        'attendant_id' => $yard['attendant'],
        'collected_by' => 'cashier',
        'payments' => [['method_id' => $yard['cash'], 'amount' => '100.00']],
    ])->assertRedirect();

    $this->actingAsMember('acme', petrolPermissions(), owner: true);
    $response = $this->get(at('stations'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('stations/index')->has('stations', 2)->where('canAddBranch', true));
    $rows = collect($response->viewData('page')['props']['stations'])->keyBy('id');

    expect($rows[$this->juba]['station']['code'])->toBe('JUBA')
        ->and($rows[$this->juba]['rostered'])->toBe(1)
        ->and($rows[$this->juba]['today']['salesMinor'])->toBe(10000)
        ->and($rows[$this->juba]['today']['shift'])->not->toBeNull()
        ->and($rows[$this->wau]['station'])->toBeNull();

    // A reporter at one station sees only it, and cannot add branches.
    $this->actingAsMember('acme', [Permissions::REPORTS_VIEW], branch: $this->wau);
    $this->get(at('stations'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('stations', 1)->where('stations.0.id', $this->wau)->where('canAddBranch', false));

    $this->actingAsMember('acme', [Permissions::SALES_CREATE], branch: $this->juba);
    $this->get(at('stations'))->assertForbidden();
});

it('places attendants at the stations a manager runs, and shows who is on a pump', function () {
    $yard = forecourt();
    $this->actingAsMember('acme', petrolPermissions(), owner: true);

    $this->post(at('staff'), [
        'name' => 'Deng Mabior',
        'employee_code' => 'a7',
        'phone' => '0912000000',
        'active' => 1,
        'stations' => [$this->juba, $this->wau],
    ])->assertSessionHasNoErrors();
    $deng = $this->asTenant('acme', fn () => Attendant::query()->where('employee_code', 'A7')->sole());
    $placed = fn () => $this->asTenant('acme', fn () => AttendantStation::query()->where('attendant_id', $deng->id)->where('active', true)->pluck('branch_id')->sort()->values()->all());
    expect($placed())->toBe(collect([$this->juba, $this->wau])->sort()->values()->all());

    // Taken off Wau; the code cannot be another attendant's; a new one needs a station.
    $this->put(at("staff/{$deng->id}"), ['name' => 'Deng Mabior', 'employee_code' => 'A7', 'active' => 1, 'stations' => [$this->juba]])->assertSessionHasNoErrors();
    expect($placed())->toBe([$this->juba]);
    $this->post(at('staff'), ['name' => 'Copy', 'employee_code' => 'A1', 'stations' => [$this->juba]])->assertSessionHasErrors('employee_code');
    $this->post(at('staff'), ['name' => 'Nowhere', 'employee_code' => 'A9'])->assertSessionHasErrors('stations');

    $response = $this->get(at('staff'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('staff/index')->where('canManage', true));
    $first = collect($response->viewData('page')['props']['attendants'])->firstWhere('id', $yard['attendant']);
    expect($first['onDuty'])->toHaveCount(1)
        ->and($first['onDuty'][0]['pump'])->toBe('N1');

    // A manager of Juba alone cannot place anyone at Wau, and leaves Wau as it was.
    $this->put(at("staff/{$deng->id}"), ['name' => 'Deng Mabior', 'employee_code' => 'A7', 'active' => 1, 'stations' => [$this->juba, $this->wau]])->assertSessionHasNoErrors();
    manager();
    $this->put(at("staff/{$deng->id}"), ['name' => 'Deng', 'employee_code' => 'A7', 'active' => 1, 'stations' => [$this->wau]])->assertSessionHasErrors('stations');
    $this->put(at("staff/{$deng->id}"), ['name' => 'Deng', 'employee_code' => 'A7', 'active' => 1, 'stations' => []])->assertSessionHasNoErrors();
    expect($placed())->toBe([$this->wau]);

    $this->actingAsMember('acme', [Permissions::ATTENDANTS_VIEW], branch: $this->juba);
    $this->post(at('staff'), ['name' => 'X', 'employee_code' => 'X1', 'stations' => [$this->juba]])->assertForbidden();
});

it('totals fuel and goods across the stations a member may see', function () {
    forecourt();
    $this->actingAsMember('acme', petrolPermissions(), owner: true);

    $this->get(at('inventory'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/index')
            ->where('fuel.totalLitres', '5000.000')
            ->where('fuel.products.0.name', 'Petrol')
            ->where('fuel.tanks.0.code', 'T1')
            ->where('fuel.tanks.0.station', 'Juba')
            ->where('goods.items', []));

    // Store staff see goods, not the tanks.
    $this->actingAsMember('acme', [Permissions::STORE_MOVE], branch: $this->juba);
    $this->get(at('inventory'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('fuel', null)->has('goods'));
});

it('lists each grade with its price, tanks and nozzles at this station', function () {
    forecourt();
    $this->post(at('products'), ['name' => 'Diesel', 'code' => 'AGO', 'active' => 1])->assertSessionHasNoErrors();

    $this->get(at('products'))->assertInertia(fn (Assert $page) => $page
        ->component('products/index')
        ->where('canManage', true)
        ->where('canPrice', true)
        ->has('products', 2)
        ->where('products.0.code', 'AGO')
        ->where('products.0.priceMinor', null)
        ->where('products.0.tanks', 0)
        ->where('products.1.code', 'PMS')
        ->where('products.1.priceMinor', 1000)
        ->where('products.1.tanks', 1)
        ->where('products.1.nozzles', 1));
});

it('gives a new attendant a username sign-in with the temporary password the manager typed', function () {
    manager();

    $this->post(at('attendants'), [
        'name' => 'Amina Lual',
        'employee_code' => 'a7',
        'phone' => '0912000000',
        'create_sign_in' => 1,
        'username' => ' Amina.Lual ',
        'password' => 'pump-start-42',
        'active' => 1,
        'at_station' => 1,
    ])->assertSessionHasNoErrors()->assertSessionHas('attendantSignIn.username', 'amina.lual');

    $request = $this->platform()->memberAccounts['amina.lual'];
    expect($request['password'])->toBe('pump-start-42')
        ->and($request['role'])->toBe('attendant')
        ->and($request['branch_id'])->toBe($this->juba);

    $attendant = $this->asTenant('acme', fn () => Attendant::query()->where('employee_code', 'A7')->sole());
    expect($attendant->user_id)->toBe($request['user_id'])
        ->and(User::query()->findOrFail($attendant->user_id)->email)->toBe('amina.lual');

    // Saving the attendant later keeps the phone and the sign-in.
    $this->put(at("attendants/{$attendant->id}"), [
        'name' => 'Amina Lual',
        'employee_code' => 'A7',
        'phone' => '0912000000',
        'user_id' => $attendant->user_id,
        'active' => 1,
        'at_station' => 1,
    ])->assertSessionHasNoErrors();
    expect($attendant->refresh()->user_id)->toBe($request['user_id'])
        ->and($attendant->phone)->toBe('0912000000');
});

it('adds no attendant when the platform refuses the sign-in', function () {
    manager();
    $this->platform()->refuseMemberAccount = ['username' => ['That username is taken. Choose another.']];

    $this->post(at('attendants'), [
        'name' => 'Amina Lual',
        'employee_code' => 'A7',
        'create_sign_in' => 1,
        'username' => 'amina',
        'password' => 'pump-start-42',
    ])->assertSessionHasErrors(['username' => 'That username is taken. Choose another.']);

    $this->post(at('attendants'), [
        'name' => 'Amina Lual',
        'employee_code' => 'A7',
        'create_sign_in' => 1,
        'username' => 'amina',
        'password' => 'short',
    ])->assertSessionHasErrors('password');

    expect($this->asTenant('acme', fn () => Attendant::query()->count()))->toBe(0);
});
