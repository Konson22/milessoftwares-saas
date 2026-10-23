<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Pharmacy\Enums\PrescriptionStatus;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\PrintedDocument;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\TillSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
| The API the mobile counter app calls: the member named by the app's
| platform access token, the branch by X-Branch-Id, the same rules as the
| web counter.
*/

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    config(['platform-client.mobile.client_id' => 'mobile-client']);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme Pharmacy', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Panadol', 'barcode' => '6001', 'sell_price_minor' => 250]));
    $this->amox = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Amoxil', 'prescription_only' => true, 'sell_price_minor' => 1200]));

    $keeper = User::factory()->create();
    $this->asTenant('acme', function () use ($keeper) {
        app(Stock::class)->add($this->para->item, $this->juba, 'P-SOON', CarbonImmutable::parse('2026-12-31'), 3, 90, $keeper);
        app(Stock::class)->add($this->para->item, $this->juba, 'P-LATE', CarbonImmutable::parse('2027-12-31'), 20, 100, $keeper);
        app(Stock::class)->add($this->amox->item, $this->juba, 'A-1', CarbonImmutable::parse('2027-06-30'), 30, 800, $keeper);
    });
});

const SELLER = ['pharmacy.sales.create'];

function appSeller(array $extra = [], ?string $branch = null, bool $openTill = true): User
{
    $branch ??= test()->juba;
    $user = test()->actingAsAppMember('acme', [...SELLER, ...$extra], branch: $branch);
    if ($openTill) {
        test()->openTill($user, $branch);
    }

    return $user;
}

function api(string $method, string $path, array $data = [], ?string $branch = null)
{
    return test()->withHeader('X-Branch-Id', $branch ?? test()->juba)
        ->json($method, test()->workspaceUrl('acme', 'api/v1/'.$path), $data);
}

function appSale(array $items, array $extra = [])
{
    return api('POST', 'sales', $extra + [
        'items' => $items,
        'submission_id' => (string) Str::uuid(),
        'payment_method' => 'cash',
        'amount_tendered_minor' => 5000,
    ]);
}

it('starts a session with the id token, then answers who and where', function () {
    $user = $this->platformUser(['name' => 'Old name']);
    $this->actingAsAppMember('acme', SELLER, user: $user, branch: $this->juba);

    $this->postJson($this->workspaceUrl('acme', 'api/session'), ['id_token' => $this->platform()->appIdToken($user->id, 'Ada Akech', 'ada@acme.test')])
        ->assertOk()
        ->assertJsonPath('user.name', 'Ada Akech');

    $this->getJson($this->workspaceUrl('acme', 'api/v1/me'))
        ->assertOk()
        ->assertJsonPath('user.name', 'Ada Akech')
        ->assertJsonPath('organization.name', 'Acme Pharmacy')
        ->assertJsonPath('organization.currency', 'SSP')
        ->assertJsonPath('organization.decimals', 2)
        ->assertJsonPath('branches', [['id' => $this->juba, 'name' => 'Juba', 'code' => 'JUB', 'city' => 'Juba']])
        ->assertJsonPath('reason', null);
});

it('works in a branch open to the member only', function () {
    appSeller();

    $this->getJson($this->workspaceUrl('acme', 'api/v1/counter'))->assertStatus(409)->assertJsonPath('code', 'branch_required');
    api('GET', 'counter', branch: $this->wau)->assertStatus(409);
    api('GET', 'counter')->assertOk()->assertJsonPath('branch.name', 'Juba');
});

it('refuses a call without the app token, and actions without the permission', function () {
    $this->withHeader('X-Branch-Id', $this->juba)->getJson($this->workspaceUrl('acme', 'api/v1/counter'))->assertUnauthorized();

    $this->actingAsAppMember('acme', ['pharmacy.sales.view'], branch: $this->juba);
    api('GET', 'items')->assertForbidden();
    api('POST', 'till', ['opening_float_minor' => 0])->assertForbidden();
});

it('describes the counter: permissions, rules, payment methods, receipt and shift', function () {
    appSeller(['pharmacy.sales.discount']);
    $this->asTenant('acme', fn () => BranchCounterSettings::query()->create([
        'branch_id' => $this->juba, 'application' => 'pharmacy', 'blind_close' => true, 'require_customer_for_restricted' => true,
        'business_name' => 'Acme Juba', 'receipt_footer' => 'Get well soon',
    ]));

    api('GET', 'counter')
        ->assertOk()
        ->assertJsonPath('can.sell', true)
        ->assertJsonPath('can.void', false)
        ->assertJsonPath('max_discount_percent', 20)
        ->assertJsonPath('counter.sells_direct', true)
        ->assertJsonPath('counter.blind_close', true)
        ->assertJsonPath('counter.require_customer_for_restricted', true)
        ->assertJsonPath('payment_methods.0', ['value' => 'cash', 'label' => 'Cash'])
        ->assertJsonMissing(['value' => 'account'])
        ->assertJsonPath('receipt.business_name', 'Acme Juba')
        ->assertJsonPath('receipt.footer', 'Get well soon')
        ->assertJsonPath('till.number', 'T-00001');
});

it('finds medicines by name or barcode, with what is sellable here', function () {
    appSeller();

    api('GET', 'items?search=pana')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->para->id)
        ->assertJsonPath('data.0.price_minor', 250)
        ->assertJsonPath('data.0.available', 23)
        ->assertJsonPath('data.0.nearest_expiry', '2026-12-31')
        ->assertJsonPath('data.0.restricted', false);

    api('GET', 'items/lookup?code=6001')->assertOk()->assertJsonPath('data.id', $this->para->id);
    api('GET', 'items/lookup?code=999')->assertNotFound();

    // Without a search: what the branch has in stock.
    api('GET', 'items')->assertOk()->assertJsonCount(2, 'data');
    api('GET', 'items', branch: null)->assertOk();
});

it('starts and closes the member\'s shift, counted blind when the branch says so', function () {
    appSeller(openTill: false);
    $this->asTenant('acme', fn () => BranchCounterSettings::query()->create(['branch_id' => $this->juba, 'application' => 'pharmacy', 'blind_close' => true]));

    api('GET', 'till')->assertOk()->assertJsonPath('data', null);
    appSale([['item_id' => $this->para->id, 'quantity' => 1]])->assertJsonValidationErrors('till');

    api('POST', 'till', ['opening_float_minor' => 10000])->assertCreated()->assertJsonPath('data.number', 'T-00001');
    api('POST', 'till', ['opening_float_minor' => 10000])->assertJsonValidationErrors('opening_float');

    appSale([['item_id' => $this->para->id, 'quantity' => 4]])->assertCreated();

    api('GET', 'till')
        ->assertJsonPath('data.summary.sales_count', 1)
        ->assertJsonPath('data.expected_cash_minor', null)
        ->assertJsonPath('data.summary.taken_minor', null);

    // Blind: a count that is off needs no reason from the cashier.
    api('POST', 'till/close', ['counted_cash_minor' => 10900])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.counted_cash_minor', 10900)
        ->assertJsonPath('data.difference_minor', null);

    expect($this->asTenant('acme', fn () => TillSession::query()->sole()))
        ->expected_cash_minor->toBe(11000)
        ->difference()->toBe(-100);
});

it('asks why the count is off, and leaves closing to managers when the branch says so', function () {
    appSeller(['pharmacy.tills.manage']);
    appSale([['item_id' => $this->para->id, 'quantity' => 2]])->assertCreated();

    api('GET', 'till')->assertJsonPath('data.expected_cash_minor', 500)->assertJsonPath('data.summary.taken_minor.cash', 500);
    api('POST', 'till/close', ['counted_cash_minor' => 400])->assertJsonValidationErrors('closing_note');
    api('POST', 'till/close', ['counted_cash_minor' => 400, 'closing_note' => 'Short by one coin'])->assertOk()->assertJsonPath('data.difference_minor', -100);

    appSeller();
    $this->asTenant('acme', fn () => BranchCounterSettings::query()->create(['branch_id' => $this->juba, 'application' => 'pharmacy', 'manager_closes_shifts' => true]));
    api('POST', 'till/close', ['counted_cash_minor' => 0])->assertForbidden();
});

it('puts cash in and takes it out of the shift once however often the app retries, seen even when blind', function () {
    appSeller(openTill: false);
    $this->asTenant('acme', fn () => BranchCounterSettings::query()->create(['branch_id' => $this->juba, 'application' => 'pharmacy', 'blind_close' => true]));

    $move = fn (array $data) => api('POST', 'till/movements', $data + ['submission_id' => (string) Str::uuid()]);
    $move(['direction' => 'in', 'amount_minor' => 500, 'reason' => 'Change'])->assertNotFound();

    api('POST', 'till', ['opening_float_minor' => 1000])->assertCreated();
    $submission = (string) Str::uuid();
    $move(['direction' => 'in', 'amount_minor' => 500, 'reason' => 'Change from the safe', 'submission_id' => $submission])->assertCreated();
    $move(['direction' => 'in', 'amount_minor' => 500, 'reason' => 'Change from the safe', 'submission_id' => $submission])
        ->assertCreated()
        ->assertJsonPath('data.expected_cash_minor', null)
        ->assertJsonPath('data.summary.cash_in_minor', 500)
        ->assertJsonPath('data.summary.cash_out_minor', 0)
        ->assertJsonCount(1, 'data.movements')
        ->assertJsonPath('data.movements.0.reason', 'Change from the safe');

    $move(['direction' => 'out', 'amount_minor' => 1501, 'reason' => 'Drop'])->assertJsonValidationErrors('amount');
    $move(['direction' => 'out', 'amount_minor' => 0, 'reason' => 'Drop'])->assertJsonValidationErrors('amount_minor');
    $move(['direction' => 'out', 'amount_minor' => 1000])->assertJsonValidationErrors('reason');
    $move(['direction' => 'out', 'amount_minor' => 1500, 'reason' => 'Drop to the safe'])
        ->assertCreated()
        ->assertJsonPath('data.summary.cash_out_minor', 1500)
        ->assertJsonPath('data.movements.1.direction', 'out')
        ->assertJsonPath('data.movements.1.counterpart', 'safe');

    $move(['direction' => 'in', 'counterpart' => 'expense', 'amount_minor' => 100, 'reason' => 'Refund'])->assertJsonValidationErrors('counterpart');
    $move(['direction' => 'in', 'counterpart' => 'bank', 'amount_minor' => 300, 'reason' => 'Change from the bank'])
        ->assertCreated()
        ->assertJsonPath('data.movements.2.label', 'From the bank');
    $move(['direction' => 'out', 'counterpart' => 'expense', 'amount_minor' => 300, 'reason' => 'Bread for the staff'])
        ->assertCreated()
        ->assertJsonPath('data.movements.3.label', 'Paid out');

    api('POST', 'till/close', ['counted_cash_minor' => 0])->assertOk();
    expect($this->asTenant('acme', fn () => TillSession::query()->sole()))->expected_cash_minor->toBe(0);
});

it('sells oldest expiry first and answers a retried sale with the same sale', function () {
    appSeller();
    $submission = (string) Str::uuid();

    appSale([['item_id' => $this->para->id, 'quantity' => 5]], ['submission_id' => $submission, 'amount_tendered_minor' => 2000, 'customer_name' => 'Walk-in'])
        ->assertCreated()
        ->assertJsonPath('duplicate', false)
        ->assertJsonPath('data.number', 'S-000001')
        ->assertJsonPath('data.total_minor', 1250)
        ->assertJsonPath('data.change_minor', 750)
        ->assertJsonPath('data.receipt_lines', [['name' => $this->para->label(), 'pack' => null, 'quantity' => 5, 'unit_price_minor' => 250, 'discount_minor' => 0, 'line_total_minor' => 1250]])
        ->assertJsonCount(2, 'data.lines');

    appSale([['item_id' => $this->para->id, 'quantity' => 5]], ['submission_id' => $submission, 'amount_tendered_minor' => 2000])
        ->assertOk()
        ->assertJsonPath('duplicate', true)
        ->assertJsonPath('data.number', 'S-000001');

    expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(1)
        ->and($this->asTenant('acme', fn () => (int) Batch::query()->where('batch_number', 'P-SOON')->value('quantity_on_hand')))->toBe(0);
});

it('keeps the web counter\'s rules', function (array $line, array $extra, string $error) {
    appSeller();
    $line['item_id'] = $this->{$line['item_id']}->id;

    appSale([$line], $extra)->assertJsonValidationErrors($error);

    expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);
})->with([
    'prescription-only' => [['item_id' => 'amox', 'quantity' => 1], [], 'items.0.item_id'],
    'more than in stock' => [['item_id' => 'para', 'quantity' => 24], ['amount_tendered_minor' => 10000], 'items.0.quantity'],
    'a discount without permission' => [['item_id' => 'para', 'quantity' => 1, 'discount_percent' => 10], [], 'items.0.discount_percent'],
    'too little cash' => [['item_id' => 'para', 'quantity' => 4], ['amount_tendered_minor' => 900], 'amount_tendered'],
    'on account' => [['item_id' => 'para', 'quantity' => 1], ['payment_method' => 'account'], 'payment_method'],
]);

it('sells only directly and paid: other counters work on the web', function () {
    appSeller();
    $this->asTenant('acme', fn () => BranchCounterSettings::query()->create(['branch_id' => $this->juba, 'application' => 'pharmacy', 'workflow' => 'order_pay_dispense']));

    api('GET', 'counter')->assertJsonPath('counter.sells_direct', false);
    appSale([['item_id' => $this->para->id, 'quantity' => 1]])->assertJsonValidationErrors('items');
});

it('lists the member\'s own sales, or the branch\'s to those who may see them', function () {
    $ada = appSeller();
    appSale([['item_id' => $this->para->id, 'quantity' => 1]])->assertCreated();
    appSeller();
    appSale([['item_id' => $this->para->id, 'quantity' => 2]])->assertCreated();

    api('GET', 'sales')->assertOk()->assertJsonPath('everyone', false)->assertJsonCount(1, 'data')->assertJsonPath('data.0.total_minor', 500);
    $first = $this->asTenant('acme', fn () => Sale::query()->where('sold_by', $ada->id)->sole());
    api('GET', "sales/{$first->id}")->assertNotFound();

    appSeller(['pharmacy.sales.view'], openTill: false);
    api('GET', 'sales?day=2026-10-22')->assertJsonPath('everyone', true)->assertJsonCount(2, 'data');
    api('GET', 'sales?day=2026-10-21')->assertJsonCount(0, 'data');
    api('GET', "sales/{$first->id}")->assertOk()->assertJsonPath('data.can_void', false);
});

it('voids and returns, refunding from the member\'s shift', function () {
    appSeller(['pharmacy.sales.void']);
    $sale = appSale([['item_id' => $this->para->id, 'quantity' => 5]])->json('data');

    api('GET', "sales/{$sale['id']}")->assertJsonPath('data.can_void', true)->assertJsonPath('data.can_return', true);

    $line = $sale['lines'][0];
    api('POST', "sales/{$sale['id']}/returns", ['reason' => 'Wrong strength', 'lines' => [['sale_line_id' => $line['id'], 'quantity' => 1, 'restock' => true]]])
        ->assertCreated()
        ->assertJsonPath('return.total_minor', 250)
        ->assertJsonPath('data.lines.0.returned', 1)
        ->assertJsonPath('data.can_void', false);

    $other = appSale([['item_id' => $this->para->id, 'quantity' => 1]])->json('data');
    api('POST', "sales/{$other['id']}/void", ['reason' => 'Rang up twice'])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided')
        ->assertJsonPath('data.void_reason', 'Rang up twice');

    // Without the permission.
    appSeller();
    api('POST', "sales/{$sale['id']}/void", ['reason' => 'x'])->assertForbidden();
});

it('logs every receipt the app prints or shares as a reprint', function () {
    appSeller();
    $sale = appSale([['item_id' => $this->para->id, 'quantity' => 1]])->json('data');

    api('POST', "sales/{$sale['id']}/printed")->assertOk()->assertJsonPath('copy', 1);
    api('POST', "sales/{$sale['id']}/printed")->assertOk()->assertJsonPath('copy', 2);

    expect($this->asTenant('acme', fn () => PrintedDocument::query()->where('automatic', false)->count()))->toBe(2);
});

it('records, verifies and dispenses a prescription, once however often the app retries', function () {
    appSeller(['pharmacy.prescriptions.view', 'pharmacy.prescriptions.record', 'pharmacy.prescriptions.verify']);

    $prescription = api('POST', 'prescriptions', [
        'patient_name' => 'Mary Akol',
        'prescriber_name' => 'Dr. John Deng',
        'issued_on' => '2026-10-20',
        'items' => [['medicine_id' => $this->amox->id, 'quantity' => 21, 'directions' => '1 capsule 3 times a day']],
    ])->assertCreated()->assertJsonPath('data.status', 'recorded')->assertJsonPath('data.can.verify', true)->json('data');

    api('GET', 'prescriptions')->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $prescription['number']);
    api('POST', "prescriptions/{$prescription['id']}/verify")->assertOk()->assertJsonPath('data.can.dispense', true);

    $item = $prescription['items'][0];
    $dispense = fn (string $submission) => api('POST', "prescriptions/{$prescription['id']}/dispense", [
        'submission_id' => $submission,
        'payment_method' => 'mobile_money',
        'payment_reference' => 'MM-1',
        'quantities' => [$item['id'] => 7],
    ]);
    $submission = (string) Str::uuid();

    $dispense($submission)->assertCreated()
        ->assertJsonPath('sale.total_minor', 8400)
        ->assertJsonPath('data.status', 'partly_dispensed')
        ->assertJsonPath('data.items.0.remaining', 14);
    $dispense($submission)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('data.items.0.remaining', 14);

    expect($this->asTenant('acme', fn () => Prescription::query()->sole()->status))->toBe(PrescriptionStatus::PartlyDispensed)
        ->and($this->asTenant('acme', fn () => Sale::query()->sole()->customer_name))->toBe('Mary Akol');
});

it('keeps another branch\'s prescriptions and sales unknown', function () {
    appSeller(['pharmacy.prescriptions.view', 'pharmacy.prescriptions.record']);
    $prescription = api('POST', 'prescriptions', [
        'patient_name' => 'Mary Akol',
        'prescriber_name' => 'Dr. John Deng',
        'issued_on' => '2026-10-20',
        'items' => [['medicine_id' => $this->amox->id, 'quantity' => 1, 'directions' => 'once']],
    ])->json('data');
    $sale = appSale([['item_id' => $this->para->id, 'quantity' => 1]])->json('data');

    $this->actingAsAppMember('acme', [...SELLER, 'pharmacy.sales.view', 'pharmacy.prescriptions.view']);
    api('GET', "prescriptions/{$prescription['id']}", branch: $this->wau)->assertNotFound();
    api('GET', "sales/{$sale['id']}", branch: $this->wau)->assertNotFound();
});
