<?php

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Journal;
use App\Models\PaymentRequest;
use App\Models\PettyCashCount;
use App\Models\PettyCashFund;
use App\Models\PettyCashVoucher;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Petty cash as an imprest fund (App\Finance\PettyCash): set up by a manager,
| funded and replenished by approved transfer requests, paid from by its
| custodian only, checked and counted by others, closed only when empty.
| Members act with what the catalog's roles really grant.
*/

function pcPermissions(string $role): array
{
    $catalog = json_decode((string) file_get_contents(base_path('platform/catalog.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach ($catalog['roles'] as $entry) {
        if ($entry['key'] === $role) {
            return $entry['permissions'];
        }
    }

    throw new RuntimeException("No role {$role} in the catalog.");
}

/**
 * Acts as a member holding $role; $who tells two holders of one role apart.
 */
function pcAs(string $role, string $who = '', string $slug = 'acme'): void
{
    $key = "{$slug}:{$role}:{$who}";
    if (! isset(test()->people[$key])) {
        test()->people[$key] = test()->platformUser(['name' => trim("{$role} {$who}"), 'email' => str_replace('_', '-', $role).($who === '' ? '' : "-{$who}").'@acme.test']);
    }
    test()->actingAsMember($slug, pcPermissions($role), user: test()->people[$key]);
}

function pcEmail(string $role, string $who = ''): string
{
    return test()->people["acme:{$role}:{$who}"]->email;
}

function pcUrl(string $path = '', string $slug = 'acme'): string
{
    return test()->workspaceUrl($slug, $path);
}

function pcAccount(string $code): string
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole()->id);
}

function pcFund(): PettyCashFund
{
    return test()->asTenant('acme', fn () => PettyCashFund::query()->sole());
}

function pcHeld(): int
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', '1040')->sole()->heldMinor());
}

function pcLatestRequest(): PaymentRequest
{
    return test()->asTenant('acme', fn () => PaymentRequest::query()->orderByDesc('number')->firstOrFail());
}

/**
 * A manager sets up "Office" (float 500), kept by the cashier.
 */
function pcSetUp(string $float = '500', ?string $replenishAt = '100'): void
{
    pcAs('cashier');
    pcAs('chief_accountant');
    test()->post(pcUrl('petty-cash'), [
        'name' => 'Office',
        'account_id' => pcAccount('1040'),
        'custodian' => pcEmail('cashier'),
        'float' => $float,
        'replenish_at' => $replenishAt,
    ])->assertSessionHasNoErrors();
}

/**
 * The custodian asks for the float (or a replenishment); the finance
 * manager approves it and another cashier disburses it.
 */
function pcFundIt(): PaymentRequest
{
    $fund = pcFund();
    pcAs('cashier');
    test()->post(pcUrl("petty-cash/{$fund->id}/replenish"), ['account_id' => pcAccount('1010'), 'pay_on' => '2026-10-20'])->assertSessionHasNoErrors();
    $request = pcLatestRequest();
    pcAs('finance_manager');
    test()->post(pcUrl("payment-requests/{$request->id}/approve"))->assertSessionHasNoErrors();
    pcAs('cashier', 'second');
    test()->post(pcUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasNoErrors();

    return $request;
}

function pcPay(string $amount = '40', array $overrides = [])
{
    $fund = pcFund();

    return test()->post(pcUrl("petty-cash/{$fund->id}/vouchers"), $overrides + [
        'paid_on' => '2026-10-20',
        'amount' => $amount,
        'payee' => 'Corner shop',
        'description' => 'Printer paper',
        'account_id' => pcAccount('6900'),
        'receipt' => UploadedFile::fake()->image('receipt.jpg'),
    ]);
}

function pcVoucher(): PettyCashVoucher
{
    return test()->asTenant('acme', fn () => PettyCashVoucher::query()->orderByDesc('number')->firstOrFail());
}

beforeEach(function () {
    $this->people = new ArrayObject;
    Storage::fake('local');
    $this->platform()->organization('acme');
    $this->setUpFinance('acme');
    $this->travelTo('2026-10-20 09:00');
    $this->asTenant('acme', function () {
        (new Account(['code' => '1040', 'name' => 'Office petty cash', 'type' => AccountType::Asset, 'bank_kind' => BankKind::PettyCash]))->save();
        Workspace::query()->sole()->forceFill(['petty_cash_max_minor' => 100_00])->save();
    });
});

describe('setting up and funding a fund', function () {
    it('is set up by a manager with a custodian who may keep petty cash, and holds nothing until funded', function () {
        pcSetUp();

        $fund = pcFund();
        expect($fund->float_minor)->toBe(500_00)
            ->and($fund->replenish_at_minor)->toBe(100_00)
            ->and($fund->custodian_id)->toBe(test()->people['acme:cashier:']->id)
            ->and(pcHeld())->toBe(0);
    });

    it('refuses a custodian who may not keep petty cash', function () {
        pcAs('accountant');
        pcAs('chief_accountant');
        $this->post(pcUrl('petty-cash'), [
            'name' => 'Office', 'account_id' => pcAccount('1040'), 'custodian' => pcEmail('accountant'), 'float' => '500',
        ])->assertSessionHasErrors('custodian');
        $this->post(pcUrl('petty-cash'), [
            'name' => 'Office', 'account_id' => pcAccount('1040'), 'custodian' => 'nobody@nowhere.test', 'float' => '500',
        ])->assertSessionHasErrors('custodian');

        expect($this->asTenant('acme', fn () => PettyCashFund::query()->count()))->toBe(0);
    });

    it('takes only a petty cash account without a fund', function () {
        pcSetUp();
        $this->post(pcUrl('petty-cash'), [
            'name' => 'Second', 'account_id' => pcAccount('1040'), 'custodian' => pcEmail('cashier'), 'float' => '200',
        ])->assertSessionHasErrors('account_id');
        $this->post(pcUrl('petty-cash'), [
            'name' => 'Till', 'account_id' => pcAccount('1000'), 'custodian' => pcEmail('cashier'), 'float' => '200',
        ])->assertSessionHasErrors('account_id');
    });

    it('is funded only through an approved and disbursed transfer request for its float', function () {
        pcSetUp();
        $fund = pcFund();
        pcAs('cashier');
        $this->post(pcUrl("petty-cash/{$fund->id}/replenish"), ['account_id' => pcAccount('1010'), 'pay_on' => '2026-10-20'])->assertSessionHasNoErrors();

        $request = pcLatestRequest();
        expect($request->kind)->toBe(PaymentRequest::TRANSFER)
            ->and($request->status)->toBe(PaymentRequest::PENDING_APPROVAL)
            ->and($request->amount_minor)->toBe(500_00)
            ->and($request->other_account_id)->toBe(pcAccount('1040'))
            ->and(pcHeld())->toBe(0);

        // The custodian raised it, so they cannot pay it out to themselves.
        pcAs('finance_manager');
        $this->post(pcUrl("payment-requests/{$request->id}/approve"))->assertSessionHasNoErrors();
        pcAs('cashier');
        $this->post(pcUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasErrors();
        pcAs('cashier', 'second');
        $this->post(pcUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasNoErrors();

        expect(pcHeld())->toBe(500_00);
    });

    it('keeps one request on its way at a time, and none once it holds its float', function () {
        pcSetUp();
        $fund = pcFund();
        pcAs('cashier');
        $this->post(pcUrl("petty-cash/{$fund->id}/replenish"), ['account_id' => pcAccount('1010'), 'pay_on' => '2026-10-20'])->assertSessionHasNoErrors();
        $this->post(pcUrl("petty-cash/{$fund->id}/replenish"), ['account_id' => pcAccount('1010'), 'pay_on' => '2026-10-20'])->assertSessionHasErrors('fund');

        $open = pcLatestRequest();
        pcAs('finance_manager');
        $this->post(pcUrl("payment-requests/{$open->id}/approve"));
        pcAs('cashier', 'second');
        $this->post(pcUrl("payment-requests/{$open->id}/disburse"), ['paid_on' => '2026-10-20']);

        pcAs('cashier');
        $this->post(pcUrl("petty-cash/{$fund->id}/replenish"), ['account_id' => pcAccount('1010'), 'pay_on' => '2026-10-20'])->assertSessionHasErrors('fund');
    });
});

describe('paying from a fund', function () {
    beforeEach(function () {
        pcSetUp();
        pcFundIt();
    });

    it('posts the voucher at once against the fund, with its receipt', function () {
        pcAs('cashier');
        pcPay('40')->assertSessionHasNoErrors();

        $voucher = pcVoucher();
        $journal = $this->asTenant('acme', fn () => Journal::query()->with('lines')->findOrFail($voucher->journal_id));
        expect($voucher->status)->toBe(PettyCashVoucher::RECORDED)
            ->and($voucher->amount_minor)->toBe(40_00)
            ->and(pcHeld())->toBe(460_00)
            ->and($journal->lines->firstWhere('account_id', pcAccount('6900'))->debit_minor)->toBe(40_00)
            ->and($journal->lines->firstWhere('account_id', pcAccount('1040'))->credit_minor)->toBe(40_00);
        Storage::disk('local')->assertExists($voucher->receipt_path);

        $this->get(pcUrl("petty-cash/{$voucher->fund_id}/vouchers/{$voucher->id}/receipt"))->assertOk();
    });

    it('lets only the custodian pay, within the limit and the cash it holds', function () {
        pcAs('cashier', 'second');
        pcPay('10')->assertSessionHasErrors('fund');

        $this->actingAsMember('acme', [], owner: true);
        pcPay('10')->assertSessionHasErrors('fund');

        pcAs('cashier');
        pcPay('100.01')->assertSessionHasErrors('amount');
        pcPay('0')->assertSessionHasErrors('amount');
        pcPay('10', ['receipt' => null])->assertSessionHasErrors('receipt');
        pcPay('10', ['account_id' => pcAccount('4000')])->assertSessionHasErrors('account_id');
        pcPay('10', ['paid_on' => '2026-10-21'])->assertSessionHasErrors('paid_on');

        for ($i = 0; $i < 5; $i++) {
            pcPay('100')->assertSessionHasNoErrors();
        }
        expect(pcHeld())->toBe(0);
        pcPay('1')->assertSessionHasErrors('amount');
        expect($this->asTenant('acme', fn () => PettyCashVoucher::query()->count()))->toBe(5);
    });

    it('needs the organization to have set its petty cash limit', function () {
        $this->asTenant('acme', fn () => Workspace::query()->sole()->forceFill(['petty_cash_max_minor' => null])->save());
        pcAs('cashier');
        pcPay('10')->assertSessionHasErrors('amount');
    });

    it('takes no vouchers while suspended', function () {
        $fund = pcFund();
        pcAs('chief_accountant');
        $this->put(pcUrl("petty-cash/{$fund->id}"), ['float' => '500', 'replenish_at' => '100', 'status' => 'suspended'])->assertSessionHasNoErrors();
        pcAs('cashier');
        pcPay('10')->assertSessionHasErrors('fund');
    });

    it('can hand money to staff as an advance, not an expense', function () {
        pcAs('cashier');
        pcPay('50', ['account_id' => pcAccount('1250'), 'description' => 'Fuel advance'])->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => Account::query()->where('code', '1250')->sole()->heldMinor()))->toBe(50_00);
    });
});

describe('checking vouchers', function () {
    beforeEach(function () {
        pcSetUp();
        pcFundIt();
        pcAs('cashier');
        pcPay('40');
    });

    it('is done by someone other than the custodian', function () {
        $voucher = pcVoucher();
        pcAs('cashier');
        $this->post(pcUrl("petty-cash/{$voucher->fund_id}/vouchers/{$voucher->id}/verify"))->assertForbidden();

        pcAs('accountant');
        $this->post(pcUrl("petty-cash/{$voucher->fund_id}/vouchers/{$voucher->id}/verify"))->assertSessionHasNoErrors();
        expect(pcVoucher()->status)->toBe(PettyCashVoucher::VERIFIED)
            ->and(pcVoucher()->checked_by)->toBe(test()->people['acme:accountant:']->id);

        $this->post(pcUrl("petty-cash/{$voucher->fund_id}/vouchers/{$voucher->id}/verify"))->assertSessionHasErrors('voucher');
    });

    it('holds an owner who keeps the fund to the same rule', function () {
        $fund = pcFund();
        $owner = $this->platformUser(['name' => 'Owner', 'email' => 'owner@acme.test']);
        $this->asTenant('acme', fn () => $fund->forceFill(['custodian_id' => $owner->id])->save());
        $this->actingAsMember('acme', [], owner: true, user: $owner);
        pcPay('10')->assertSessionHasNoErrors();

        $voucher = pcVoucher();
        $this->post(pcUrl("petty-cash/{$fund->id}/vouchers/{$voucher->id}/verify"))->assertSessionHasErrors('voucher');
        $this->post(pcUrl("petty-cash/{$fund->id}/counts"), ['counted' => '450'])->assertSessionHasErrors('counted');
    });

    it('reverses a rejected voucher, and the fund is owed the cash', function () {
        $voucher = pcVoucher();
        pcAs('accountant');
        $this->post(pcUrl("petty-cash/{$voucher->fund_id}/vouchers/{$voucher->id}/reject"), ['note' => 'No receipt detail'])->assertSessionHasNoErrors();

        $rejected = pcVoucher();
        expect($rejected->status)->toBe(PettyCashVoucher::REJECTED)
            ->and($rejected->reversal_journal_id)->not->toBeNull()
            ->and(pcHeld())->toBe(500_00)
            ->and($this->asTenant('acme', fn () => Account::query()->where('code', '6900')->sole()->heldMinor()))->toBe(0);
    });

    it('cannot check a voucher of another fund through this one', function () {
        $voucher = pcVoucher();
        $other = $this->asTenant('acme', function () {
            (new Account(['code' => '1041', 'name' => 'Store petty cash', 'type' => AccountType::Asset, 'bank_kind' => BankKind::PettyCash]))->save();

            return PettyCashFund::query()->create([
                'name' => 'Store', 'account_id' => Account::query()->where('code', '1041')->sole()->id,
                'custodian_id' => test()->people['acme:cashier:']->id, 'float_minor' => 100_00, 'status' => 'active',
                'created_by' => test()->people['acme:cashier:']->id,
            ]);
        });
        pcAs('accountant');
        $this->post(pcUrl("petty-cash/{$other->id}/vouchers/{$voucher->id}/verify"))->assertNotFound();
    });
});

describe('replenishing', function () {
    beforeEach(function () {
        pcSetUp();
        pcFundIt();
        pcAs('cashier');
        pcPay('40');
        pcPay('60');
    });

    it('waits until every voucher is checked', function () {
        pcAs('cashier');
        $this->post(pcUrl('petty-cash/'.pcFund()->id.'/replenish'), ['account_id' => pcAccount('1010'), 'pay_on' => '2026-10-20'])->assertSessionHasErrors('fund');
    });

    it('asks for what was spent, pays back each voucher once and books no expense twice', function () {
        pcAs('accountant');
        foreach ($this->asTenant('acme', fn () => PettyCashVoucher::query()->get()) as $voucher) {
            $this->post(pcUrl("petty-cash/{$voucher->fund_id}/vouchers/{$voucher->id}/verify"))->assertSessionHasNoErrors();
        }

        $request = pcFundIt();
        expect($request->amount_minor)->toBe(100_00)
            ->and($request->details['voucher_ids'])->toHaveCount(2)
            ->and(pcHeld())->toBe(500_00)
            ->and($this->asTenant('acme', fn () => PettyCashVoucher::query()->where('replenished_by_id', $request->id)->count()))->toBe(2)
            // Spent once: the replenishment is a transfer, not an expense.
            ->and($this->asTenant('acme', fn () => Account::query()->where('code', '6900')->sole()->heldMinor()))->toBe(100_00);
    });
});

describe('counting the cash', function () {
    beforeEach(function () {
        pcSetUp();
        pcFundIt();
        pcAs('cashier');
        pcPay('40');
    });

    it('is not done by the custodian', function () {
        pcAs('cashier');
        $this->post(pcUrl('petty-cash/'.pcFund()->id.'/counts'), ['counted' => '460'])->assertForbidden();
    });

    it('records an agreeing count without posting anything', function () {
        $journals = $this->asTenant('acme', fn () => Journal::query()->count());
        pcAs('accountant');
        $this->post(pcUrl('petty-cash/'.pcFund()->id.'/counts'), ['counted' => '460'])->assertSessionHasNoErrors();

        $count = $this->asTenant('acme', fn () => PettyCashCount::query()->sole());
        expect($count->difference_minor)->toBe(0)
            ->and($count->journal_id)->toBeNull()
            ->and($this->asTenant('acme', fn () => Journal::query()->count()))->toBe($journals);
    });

    it('posts a shortage to cash over and short, only with an explanation', function () {
        pcAs('accountant');
        $this->post(pcUrl('petty-cash/'.pcFund()->id.'/counts'), ['counted' => '450'])->assertSessionHasErrors('note');
        $this->post(pcUrl('petty-cash/'.pcFund()->id.'/counts'), ['counted' => '450', 'note' => 'Coins missing'])->assertSessionHasNoErrors();

        $count = $this->asTenant('acme', fn () => PettyCashCount::query()->sole());
        expect($count->book_minor)->toBe(460_00)
            ->and($count->difference_minor)->toBe(-10_00)
            ->and(pcHeld())->toBe(450_00)
            ->and($this->asTenant('acme', fn () => Account::system(Account::CASH_OVER_SHORT)->heldMinor()))->toBe(10_00);
    });
});

describe('handover and closing', function () {
    beforeEach(function () {
        pcSetUp();
        pcFundIt();
        pcAs('cashier');
        pcPay('40');
    });

    it('hands the fund over only after a count, and only to someone who may keep it', function () {
        $fund = pcFund();
        pcAs('cashier', 'second');
        pcAs('accountant');
        pcAs('chief_accountant');
        $this->put(pcUrl("petty-cash/{$fund->id}/custodian"), ['custodian' => pcEmail('cashier', 'second')])->assertSessionHasErrors('custodian');

        pcAs('accountant');
        $this->post(pcUrl("petty-cash/{$fund->id}/counts"), ['counted' => '460']);
        pcAs('chief_accountant');
        $this->put(pcUrl("petty-cash/{$fund->id}/custodian"), ['custodian' => pcEmail('accountant')])->assertSessionHasErrors('custodian');
        $this->put(pcUrl("petty-cash/{$fund->id}/custodian"), ['custodian' => pcEmail('cashier', 'second')])->assertSessionHasNoErrors();

        expect(pcFund()->custodian_id)->toBe(test()->people['acme:cashier:second']->id);
        pcAs('cashier');
        pcPay('5')->assertSessionHasErrors('fund');
    });

    it('closes only when its vouchers are checked and its cash has gone back', function () {
        $fund = pcFund();
        pcAs('chief_accountant');
        $this->post(pcUrl("petty-cash/{$fund->id}/close"))->assertSessionHasErrors('fund');

        $voucher = pcVoucher();
        pcAs('accountant');
        $this->post(pcUrl("petty-cash/{$fund->id}/vouchers/{$voucher->id}/verify"));
        pcAs('chief_accountant');
        $this->post(pcUrl("petty-cash/{$fund->id}/close"))->assertSessionHasErrors('fund');

        // The cash goes back to the bank like any other money out: a request.
        pcAs('finance_officer');
        $this->post(pcUrl('payment-requests'), [
            'kind' => 'transfer', 'account_id' => pcAccount('1040'), 'to_account_id' => pcAccount('1010'),
            'amount' => '460', 'pay_on' => '2026-10-20', 'description' => 'Closing the office fund',
        ])->assertSessionHasNoErrors();
        $back = pcLatestRequest();
        pcAs('finance_manager');
        $this->post(pcUrl("payment-requests/{$back->id}/approve"));
        pcAs('cashier', 'second');
        $this->post(pcUrl("payment-requests/{$back->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasNoErrors();

        pcAs('chief_accountant');
        $this->post(pcUrl("petty-cash/{$fund->id}/close"))->assertSessionHasNoErrors();
        expect(pcFund()->status)->toBe(PettyCashFund::CLOSED);

        pcAs('cashier');
        pcPay('1')->assertSessionHasErrors('fund');
    });
});

describe('cash never goes below nothing', function () {
    it('refuses to pay out more than a cash account holds, and the request stays ready', function () {
        pcSetUp();
        pcFundIt();
        pcAs('finance_officer');
        $this->post(pcUrl('payment-requests'), [
            'kind' => 'expense', 'account_id' => pcAccount('1040'), 'other_account_id' => pcAccount('6000'),
            'amount' => '600', 'pay_on' => '2026-10-20', 'description' => 'Too much',
        ])->assertSessionHasNoErrors();
        $request = pcLatestRequest();
        pcAs('finance_manager');
        $this->post(pcUrl("payment-requests/{$request->id}/approve"));
        pcAs('cashier', 'second');
        $this->post(pcUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasErrors();

        expect(pcLatestRequest()->status)->toBe(PaymentRequest::APPROVED)
            ->and(pcHeld())->toBe(500_00);
    });

    it('still lets a bank go overdrawn', function () {
        pcAs('finance_officer');
        $this->post(pcUrl('payment-requests'), [
            'kind' => 'expense', 'account_id' => pcAccount('1010'), 'other_account_id' => pcAccount('6000'),
            'amount' => '600', 'pay_on' => '2026-10-20', 'description' => 'Rent',
        ])->assertSessionHasNoErrors();
        $request = pcLatestRequest();
        pcAs('finance_manager');
        $this->post(pcUrl("payment-requests/{$request->id}/approve"));
        pcAs('cashier');
        $this->post(pcUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasNoErrors();
    });
});

describe('access', function () {
    beforeEach(function () {
        pcSetUp();
    });

    it('shows funds to those who may see the banks, and lets only the right people act', function () {
        $fund = pcFund();
        pcAs('auditor');
        $this->get(pcUrl('petty-cash'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('petty-cash/index')
            ->where('funds.0.name', 'Office')
            ->where('canManage', false));
        $this->get(pcUrl("petty-cash/{$fund->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can', ['pay' => false, 'replenish' => false, 'count' => false, 'manage' => false]));
        $this->post(pcUrl("petty-cash/{$fund->id}/close"))->assertForbidden();
        $this->post(pcUrl("petty-cash/{$fund->id}/counts"), ['counted' => '0'])->assertForbidden();
        pcPay('1')->assertForbidden();

        pcAs('system_administrator');
        $this->get(pcUrl('petty-cash'))->assertForbidden();

        // A cashier who does not keep this fund cannot ask for its money.
        pcAs('cashier', 'second');
        $this->post(pcUrl("petty-cash/{$fund->id}/replenish"), ['account_id' => pcAccount('1010'), 'pay_on' => '2026-10-20'])->assertForbidden();
    });

    it('keeps each organization to its own funds', function () {
        $fund = pcFund();
        $this->platform()->organization('globex');
        $this->setUpFinance('globex');
        $this->actingAsMember('globex', [], owner: true);

        $this->get(pcUrl("petty-cash/{$fund->id}", 'globex'))->assertNotFound();
        $this->get(pcUrl('petty-cash', 'globex'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('funds', 0));
    });

    it('lets only the approval rules configurer set the petty cash limit, and records the change', function () {
        pcAs('chief_accountant');
        $this->put(pcUrl('petty-cash/settings'), ['petty_cash_max' => '250'])->assertForbidden();

        pcAs('system_administrator');
        $this->put(pcUrl('petty-cash/settings'), ['petty_cash_max' => '250'])->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => Workspace::query()->sole()->petty_cash_max_minor))->toBe(250_00)
            ->and($this->asTenant('acme', fn () => AuditEvent::query()->where('action', 'approval_rules.petty_cash_changed')->sole()->after))->toBe(['max_minor' => 250_00]);
    });

    it('audits every step', function () {
        pcFundIt();
        pcAs('cashier');
        pcPay('40');
        $voucher = pcVoucher();
        pcAs('accountant');
        $this->post(pcUrl("petty-cash/{$voucher->fund_id}/vouchers/{$voucher->id}/verify"));
        $this->post(pcUrl("petty-cash/{$voucher->fund_id}/counts"), ['counted' => '460']);

        expect($this->asTenant('acme', fn () => AuditEvent::query()->where('action', 'like', 'petty_cash.%')->orderBy('created_at')->pluck('action')->all()))
            ->toBe(['petty_cash.fund_created', 'petty_cash.replenishment_requested', 'petty_cash.voucher_recorded', 'petty_cash.voucher_verified', 'petty_cash.counted']);
    });
});
