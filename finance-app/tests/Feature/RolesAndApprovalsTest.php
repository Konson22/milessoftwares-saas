<?php

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Finance\Currencies;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\DimensionValue;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\JournalDraft;
use App\Models\PaymentRequest;
use App\Models\RecurringJournal;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\TaxReturn;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;

/**
 * The permissions a role holds in the published catalog: these tests act with
 * what the catalog really grants, so changing a role changes what they prove.
 *
 * @return list<string>
 */
function rolePermissions(string $role): array
{
    $catalog = json_decode((string) file_get_contents(base_path('platform/catalog.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($catalog['roles'] as $entry) {
        if ($entry['key'] === $role) {
            return $entry['permissions'];
        }
    }

    throw new RuntimeException("No role {$role} in the catalog.");
}

function asRole(string $role): void
{
    $staff = test()->staff;
    if (! isset($staff[$role])) {
        $staff[$role] = test()->platformUser(['name' => $role]);
    }
    test()->actingAsMember('acme', rolePermissions($role), user: $staff[$role]);
}

function raUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path);
}

function raAccount(string $code): string
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole()->id);
}

function raRequest(): PaymentRequest
{
    return test()->asTenant('acme', fn () => PaymentRequest::query()->orderByDesc('number')->firstOrFail());
}

function raRules(array $rules = []): void
{
    asRole('system_administrator');
    test()->put(raUrl('payment-requests/settings'), $rules + [
        'payment_approval' => '1',
        'threshold' => null,
        'payment_review' => '0',
        'payment_separation' => '1',
    ])->assertSessionHasNoErrors();
}

function raExpense(string $amount = '300')
{
    return test()->post(raUrl('payment-requests'), [
        'kind' => 'expense',
        'account_id' => raAccount('1010'),
        'other_account_id' => raAccount('6000'),
        'amount' => $amount,
        'pay_on' => '2026-10-05',
        'description' => 'October rent',
    ]);
}

function raJournals(): int
{
    return test()->asTenant('acme', fn () => Journal::query()->count());
}

function raBalance(string $code): int
{
    return test()->asTenant('acme', function () use ($code) {
        $account = Account::query()->where('code', $code)->sole();

        return (int) $account->lines()->sum('debit_minor') - (int) $account->lines()->sum('credit_minor');
    });
}

beforeEach(function () {
    $this->staff = new ArrayObject;
    $this->platform()->organization('acme');
    $this->setUpFinance('acme');
    $this->travelTo('2026-10-20 09:00');
});

describe('the catalog', function () {
    it('declares the finance and accounting roles, and no duty a role conflicts with itself', function () {
        $catalog = json_decode((string) file_get_contents(base_path('platform/catalog.json')), true, flags: JSON_THROW_ON_ERROR);

        expect(array_column($catalog['roles'], 'key'))->toContain('finance_manager', 'finance_officer', 'cashier', 'chief_accountant', 'accountant', 'auditor', 'system_administrator');

        foreach ($catalog['roles'] as $role) {
            foreach ($catalog['separation_of_duties'] as $pair) {
                expect(array_intersect($pair['permissions'], $role['permissions']))
                    ->not->toHaveCount(2, "{$role['key']} holds both of ".implode(' and ', $pair['permissions']));
            }
        }
    });

    it('keeps the auditor read only', function () {
        foreach (rolePermissions('auditor') as $permission) {
            expect($permission)->toMatch('/\.(view|dashboard\.(finance|accounting))$/');
        }
    });

    it('gives each step of a payment to a different role', function () {
        $holders = fn (string $permission) => array_values(array_filter(
            ['finance_manager', 'finance_officer', 'cashier', 'chief_accountant', 'accountant', 'auditor', 'system_administrator'],
            fn (string $role) => in_array($permission, rolePermissions($role), true),
        ));

        expect($holders('finance.payments.prepare'))->toBe(['finance_officer'])
            ->and($holders('finance.payments.review'))->toBe(['chief_accountant', 'accountant'])
            ->and($holders('finance.payments.approve'))->toBe(['finance_manager'])
            ->and($holders('finance.payments.disburse'))->toBe(['cashier'])
            ->and($holders('finance.journals.reverse'))->toBe(['chief_accountant'])
            ->and($holders('finance.periods.manage'))->toBe(['chief_accountant'])
            ->and($holders('finance.approvals.configure'))->toBe(['system_administrator']);
    });
});

describe('authorization', function () {
    it('allows each role only its own actions, by the API as much as the page', function (string $role, string $method, string $path, bool $allowed) {
        asRole($role);
        $response = $this->call($method, raUrl($path));

        // A permitted member gets past the gate (validation, or a page); nobody else does.
        $allowed ? expect($response->getStatusCode())->not->toBe(403) : $response->assertForbidden();
    })->with([
        ['finance_officer', 'POST', 'payment-requests', true],
        ['cashier', 'POST', 'payment-requests', false],
        ['finance_manager', 'POST', 'payment-requests', false],
        ['accountant', 'POST', 'payment-requests', false],
        ['auditor', 'POST', 'payment-requests', false],
        ['finance_officer', 'POST', 'supplier-payments', false],
        ['finance_manager', 'POST', 'supplier-payments', false],
        ['cashier', 'POST', 'supplier-payments', false],
        ['accountant', 'POST', 'supplier-payments', false],
        ['chief_accountant', 'POST', 'supplier-payments', false],
        ['finance_officer', 'POST', 'banking/money-out', false],
        ['cashier', 'POST', 'banking/money-out', false],
        ['accountant', 'POST', 'banking/transfers', false],
        ['cashier', 'POST', 'banking/money-in', true],
        ['accountant', 'POST', 'bills', true],
        ['finance_officer', 'POST', 'bills', false],
        ['accountant', 'POST', 'journals', true],
        ['finance_manager', 'POST', 'journals', false],
        ['cashier', 'POST', 'journals', false],
        ['auditor', 'POST', 'journals', false],
        ['accountant', 'POST', 'reconciliation', true],
        ['cashier', 'POST', 'reconciliation', false],
        ['finance_manager', 'POST', 'reconciliation', false],
        ['chief_accountant', 'POST', 'periods/years', true],
        ['accountant', 'POST', 'periods/years', false],
        ['finance_manager', 'POST', 'periods/years', false],
        ['finance_manager', 'POST', 'budgets', true],
        ['accountant', 'POST', 'budgets', false],
        ['finance_manager', 'PUT', 'payment-requests/settings', false],
        ['chief_accountant', 'PUT', 'journal-approvals/settings', false],
        ['system_administrator', 'PUT', 'payment-requests/settings', true],
        ['system_administrator', 'POST', 'journals', false],
        ['auditor', 'GET', 'payment-requests', true],
        ['auditor', 'GET', 'reports/trial-balance', true],
        ['cashier', 'GET', 'reports/trial-balance', false],
    ]);

    it('lets only the chief accountant reverse a posted journal', function () {
        asRole('accountant');
        $this->post(raUrl('journals'), ['entry_date' => '2026-10-05', 'memo' => 'Rent', 'lines' => [
            ['account_id' => raAccount('6000'), 'debit' => '10'],
            ['account_id' => raAccount('1010'), 'credit' => '10'],
        ]])->assertSessionHasNoErrors();
        $journal = $this->asTenant('acme', fn () => Journal::query()->sole());

        $reverse = ['entry_date' => '2026-10-06', 'reason' => 'Wrong'];
        $this->post(raUrl("journals/{$journal->id}/reverse"), $reverse)->assertForbidden();
        expect(raJournals())->toBe(1);

        asRole('chief_accountant');
        $this->post(raUrl("journals/{$journal->id}/reverse"), $reverse)->assertSessionHasNoErrors();
        expect(raJournals())->toBe(2);
    });

    it('holds a journal from someone who may only prepare it, even with approval off', function () {
        $user = $this->platformUser();
        $this->actingAsMember('acme', ['finance.accounts.view', 'finance.journals.view', 'finance.journals.prepare'], user: $user);

        $this->post(raUrl('journals'), ['entry_date' => '2026-10-05', 'memo' => 'Rent', 'lines' => [
            ['account_id' => raAccount('6000'), 'debit' => '10'],
            ['account_id' => raAccount('1010'), 'credit' => '10'],
        ]])->assertSessionHasNoErrors()->assertRedirect(raUrl('journal-approvals'));

        expect(raJournals())->toBe(0)
            ->and($this->asTenant('acme', fn () => JournalDraft::query()->sole()->status))->toBe(JournalDraft::PENDING);
    });

    it('gives holders of several roles the union of their permissions', function () {
        $user = $this->platformUser();
        $this->platform()->grant('acme', (string) $user->getAuthIdentifier(), [
            ['permissions' => rolePermissions('finance_officer')],
            ['permissions' => rolePermissions('cashier')],
        ]);
        $this->actingAs($user)->withSession([RequirePlatformAccess::SESSION_TENANT => $this->platform()->organization('acme')['id']]);

        raExpense()->assertSessionHasNoErrors();
        $this->post(raUrl('banking/money-in'))->assertSessionHasErrors('account_id');
    });
});

describe('segregation of duties', function () {
    it('takes a payment from finance through to accounting, on one ledger', function () {
        raRules(['payment_review' => '1']);

        // Finance Officer prepares: nothing moves.
        asRole('finance_officer');
        raExpense()->assertSessionHasNoErrors();
        $request = raRequest();
        expect($request->status)->toBe(PaymentRequest::PENDING_REVIEW)
            ->and(raJournals())->toBe(0);

        // The steps cannot be taken out of order, whoever calls them.
        asRole('finance_manager');
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertSessionHasErrors('request');
        asRole('cashier');
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasErrors('request');
        expect(raJournals())->toBe(0);

        // Accounting reviews, Finance approves, the cashier disburses.
        asRole('accountant');
        $this->post(raUrl("payment-requests/{$request->id}/review"))->assertSessionHasNoErrors();
        asRole('finance_manager');
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertSessionHasNoErrors();

        // Approved by Finance: Accounting sees it, not in the books yet.
        asRole('accountant');
        $this->get(raUrl('payment-requests'))->assertInertia(fn (Assert $page) => $page
            ->where('open.0.number', $request->number)
            ->where('open.0.status', PaymentRequest::APPROVED));
        $this->get(raUrl('?view=accounting'))->assertInertia(fn (Assert $page) => $page
            ->where('view', 'accounting')
            ->where('accounting.unposted.payments.count', 1));

        asRole('cashier');
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20', 'reference' => 'CHQ-1'])->assertSessionHasNoErrors();

        $journal = $this->asTenant('acme', fn () => Journal::query()->sole());
        expect(raRequest()->status)->toBe(PaymentRequest::DISBURSED)
            ->and(raRequest()->journal_id)->toBe($journal->id)
            ->and($journal->approved_by)->toBe($this->staff['finance_manager']->getAuthIdentifier())
            ->and($journal->created_by)->toBe($this->staff['cashier']->getAuthIdentifier())
            ->and(raBalance('6000'))->toBe(30000)
            ->and(raBalance('1010'))->toBe(-30000);

        // The same record shows in the statements and in Accounting's ledger.
        asRole('chief_accountant');
        $this->get(raUrl('reports/profit-and-loss?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page->where('expensesMinor', 30000));
        $this->get(raUrl("journals/{$journal->id}"))->assertInertia(fn (Assert $page) => $page->where('journal.approvedBy', 'finance_manager'));

        // Once only.
        asRole('cashier');
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasErrors('request');
        expect(raJournals())->toBe(1);
    });

    it('does not let one person act twice on a payment, owners included', function () {
        raRules(['payment_review' => '1']);
        $owner = $this->platformUser();
        $this->actingAsMember('acme', [], owner: true, user: $owner);

        raExpense()->assertSessionHasNoErrors();
        $request = raRequest();
        $this->post(raUrl("payment-requests/{$request->id}/review"))->assertSessionHasErrors('request');
        $this->post(raUrl("payment-requests/{$request->id}/reject"), ['reason' => 'x'])->assertSessionHasErrors('request');

        // Reviewed by someone else; the owner still prepared it.
        asRole('accountant');
        $this->post(raUrl("payment-requests/{$request->id}/review"))->assertSessionHasNoErrors();
        $this->actingAsMember('acme', [], owner: true, user: $owner);
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertSessionHasErrors('request');
        expect(raRequest()->status)->toBe(PaymentRequest::PENDING_APPROVAL);

        // An approver does not disburse what they approved.
        $approver = $this->platformUser();
        $this->actingAsMember('acme', ['finance.payments.approve', 'finance.payments.disburse'], user: $approver);
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertSessionHasNoErrors();
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasErrors('request');
        expect(raJournals())->toBe(0);
    });

    it('does not let a reviewer approve what they reviewed', function () {
        raRules(['payment_review' => '1']);
        asRole('finance_officer');
        raExpense()->assertSessionHasNoErrors();
        $request = raRequest();

        $this->actingAsMember('acme', ['finance.payments.review', 'finance.payments.approve']);
        $this->post(raUrl("payment-requests/{$request->id}/review"))->assertSessionHasNoErrors();
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertSessionHasErrors('request');
        expect(raRequest()->status)->toBe(PaymentRequest::PENDING_APPROVAL);
    });

    it('lets a very small team turn separation off', function () {
        raRules(['payment_separation' => '0']);
        $this->actingAsMember('acme', [], owner: true);

        raExpense()->assertSessionHasNoErrors();
        $request = raRequest();
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertSessionHasNoErrors();
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasNoErrors();

        expect(raRequest()->status)->toBe(PaymentRequest::DISBURSED);
    });

    it('needs no approval below the limit, but still a second person to pay', function () {
        raRules(['threshold' => '1000']);
        $user = $this->platformUser();
        $this->actingAsMember('acme', ['finance.payments.view', 'finance.payments.prepare', 'finance.payments.disburse'], user: $user);

        raExpense('999.99')->assertSessionHasNoErrors();
        $small = raRequest();
        expect($small->status)->toBe(PaymentRequest::APPROVED);
        $this->post(raUrl("payment-requests/{$small->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasErrors('request');

        asRole('cashier');
        $this->post(raUrl("payment-requests/{$small->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasNoErrors();

        asRole('finance_officer');
        raExpense('1000')->assertSessionHasNoErrors();
        expect(raRequest()->status)->toBe(PaymentRequest::PENDING_APPROVAL);
    });

    it('lets a rejection and a withdrawal stand, and only the right people make them', function () {
        raRules();
        asRole('finance_officer');
        raExpense()->assertSessionHasNoErrors();
        $first = raRequest();
        raExpense('50')->assertSessionHasNoErrors();
        $second = raRequest();

        // A reviewer cannot reject once it has passed review.
        asRole('accountant');
        $this->post(raUrl("payment-requests/{$first->id}/reject"), ['reason' => 'No'])->assertForbidden();

        asRole('finance_manager');
        $this->post(raUrl("payment-requests/{$first->id}/reject"), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(raUrl("payment-requests/{$first->id}/reject"), ['reason' => 'Paid already'])->assertSessionHasNoErrors();
        $this->post(raUrl("payment-requests/{$first->id}/approve"))->assertSessionHasErrors('request');

        asRole('finance_officer');
        $this->post(raUrl("payment-requests/{$second->id}/withdraw"))->assertSessionHasNoErrors();

        $statuses = $this->asTenant('acme', fn () => PaymentRequest::query()->orderBy('number')->pluck('status')->all());
        expect($statuses)->toBe([PaymentRequest::REJECTED, PaymentRequest::WITHDRAWN])->and(raJournals())->toBe(0);
    });

    it('keeps a request approved when the books refuse the payment', function () {
        raRules();
        asRole('finance_officer');
        raExpense()->assertSessionHasNoErrors();
        $request = raRequest();
        asRole('finance_manager');
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertSessionHasNoErrors();

        // Locked up to September: a date there is refused.
        asRole('chief_accountant');
        $this->post(raUrl('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12])->assertSessionHasNoErrors();
        foreach ($this->asTenant('acme', fn () => FiscalPeriod::query()->whereDate('starts_on', '<=', '2026-09-01')->orderBy('starts_on')->get()) as $month) {
            $this->post(raUrl("periods/{$month->id}/lock"))->assertSessionHasNoErrors();
        }

        asRole('cashier');
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-09-15'])->assertSessionHasErrors('entry_date');
        expect(raRequest()->status)->toBe(PaymentRequest::APPROVED)->and(raJournals())->toBe(0);
    });
});

describe('no way round approval', function () {
    beforeEach(function () {
        raRules();
        // Someone holding the old direct permissions, and more.
        $this->actingAsMember('acme', [
            'finance.payables.view', 'finance.payables.manage', 'finance.banking.view', 'finance.banking.manage',
            'finance.banking.reconcile', 'finance.accounts.view', 'finance.journals.view', 'finance.journals.post',
        ]);
    });

    it('turns a direct supplier payment into a request', function () {
        $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Acme Supplies']));

        $this->post(raUrl('supplier-payments'), [
            'supplier_id' => $supplier->id, 'account_id' => raAccount('1010'), 'amount' => '100', 'paid_on' => '2026-10-05',
        ])->assertSessionHasNoErrors()->assertRedirect(raUrl('payment-requests'));

        expect($this->asTenant('acme', fn () => SupplierPayment::query()->count()))->toBe(0)
            ->and(raJournals())->toBe(0)
            ->and(raRequest()->kind)->toBe(PaymentRequest::SUPPLIER)
            ->and(raRequest()->status)->toBe(PaymentRequest::PENDING_APPROVAL);

        $this->get(raUrl('payment-requests'))->assertOk();
    });

    it('turns money out into a request', function () {
        $this->post(raUrl('banking/money-out'), [
            'account_id' => raAccount('1010'), 'other_account_id' => raAccount('6000'), 'amount' => '100', 'entry_date' => '2026-10-05', 'description' => 'Rent',
        ])->assertSessionHasNoErrors()->assertRedirect(raUrl('payment-requests'));

        expect(raJournals())->toBe(0)->and(raRequest()->kind)->toBe(PaymentRequest::EXPENSE);
    });

    it('turns a transfer into a request', function () {
        $this->post(raUrl('banking/transfers'), [
            'account_id' => raAccount('1010'), 'to_account_id' => raAccount('1000'), 'amount' => '100', 'entry_date' => '2026-10-05', 'description' => 'To the till',
        ])->assertSessionHasNoErrors()->assertRedirect(raUrl('payment-requests'));

        expect(raJournals())->toBe(0)->and(raRequest()->kind)->toBe(PaymentRequest::TRANSFER);
    });

    it('turns a manual journal that takes money out of a bank into a request, but not others', function () {
        $journal = fn (string $credit) => $this->post(raUrl('journals'), ['entry_date' => '2026-10-05', 'memo' => 'Entry', 'lines' => [
            ['account_id' => raAccount('6000'), 'debit' => '100'],
            ['account_id' => raAccount($credit), 'credit' => '100'],
        ]])->assertSessionHasNoErrors();

        $journal('1010')->assertRedirect(raUrl('payment-requests'));
        expect(raJournals())->toBe(0)->and(raRequest()->kind)->toBe(PaymentRequest::JOURNAL);

        $journal('2000');
        expect(raJournals())->toBe(1);
    });

    it('refuses to enter an outgoing statement line, but lets money in through', function () {
        $this->post(raUrl('reconciliation'), [
            'account_id' => raAccount('1010'), 'ends_on' => '2026-10-31', 'opening_balance' => '0', 'closing_balance' => '50',
            'rows' => [
                ['date' => '2026-10-02', 'description' => 'Paid out', 'amount' => '-50'],
                ['date' => '2026-10-03', 'description' => 'Paid in', 'amount' => '100'],
            ],
        ])->assertSessionHasNoErrors();
        $lines = $this->asTenant('acme', fn () => BankStatementLine::query()->orderBy('position')->get());

        $this->post(raUrl("reconciliation/lines/{$lines[0]->id}/enter"), ['account_id' => raAccount('6000')])->assertSessionHasErrors('line');
        expect(raJournals())->toBe(0);

        $this->post(raUrl("reconciliation/lines/{$lines[1]->id}/enter"), ['account_id' => raAccount('4900')])->assertSessionHasNoErrors();
        expect(raJournals())->toBe(1);
    });

    it('leaves direct payments alone while approval is off', function () {
        raRules(['payment_approval' => '0']);
        $this->actingAsMember('acme', ['finance.banking.view', 'finance.banking.manage']);

        $this->post(raUrl('banking/money-out'), [
            'account_id' => raAccount('1010'), 'other_account_id' => raAccount('6000'), 'amount' => '100', 'entry_date' => '2026-10-05', 'description' => 'Rent',
        ])->assertSessionHasNoErrors();

        expect(raJournals())->toBe(1)
            ->and($this->asTenant('acme', fn () => PaymentRequest::query()->count()))->toBe(0);
    });
});

describe('other request kinds', function () {
    it('pays a supplier against a bill through a request', function () {
        asRole('accountant');
        $this->post(raUrl('suppliers'), ['name' => 'Acme Supplies'])->assertSessionHasNoErrors();
        $supplier = $this->asTenant('acme', fn () => Supplier::query()->sole());
        $this->post(raUrl('bills'), [
            'supplier_id' => $supplier->id, 'bill_date' => '2026-10-01', 'due_on' => '2026-10-30',
            'lines' => [['account_id' => raAccount('6200'), 'description' => 'Power', 'amount' => '200']],
        ])->assertSessionHasNoErrors();
        $bill = $this->asTenant('acme', fn () => Bill::query()->sole());

        asRole('finance_officer');
        $this->post(raUrl('payment-requests'), [
            'kind' => 'supplier', 'supplier_id' => $supplier->id, 'account_id' => raAccount('1010'), 'amount' => '200', 'pay_on' => '2026-10-05',
            'allocations' => [$bill->id => '200'],
        ])->assertSessionHasNoErrors();
        $request = raRequest();

        asRole('finance_manager');
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertSessionHasNoErrors();
        asRole('cashier');
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => Bill::query()->sole()->paid_minor))->toBe(20000)
            ->and(raRequest()->supplier_payment_id)->not->toBeNull();
    });

    it('refuses more than a bill is owed when it is prepared', function () {
        asRole('accountant');
        $this->post(raUrl('suppliers'), ['name' => 'Acme Supplies'])->assertSessionHasNoErrors();
        $supplier = $this->asTenant('acme', fn () => Supplier::query()->sole());
        $this->post(raUrl('bills'), [
            'supplier_id' => $supplier->id, 'bill_date' => '2026-10-01',
            'lines' => [['account_id' => raAccount('6200'), 'description' => 'Power', 'amount' => '200']],
        ])->assertSessionHasNoErrors();
        $bill = $this->asTenant('acme', fn () => Bill::query()->sole());

        asRole('finance_officer');
        $this->post(raUrl('payment-requests'), [
            'kind' => 'supplier', 'supplier_id' => $supplier->id, 'account_id' => raAccount('1010'), 'amount' => '300', 'pay_on' => '2026-10-05',
            'allocations' => [$bill->id => '300'],
        ])->assertSessionHasErrors('allocations');
    });

    it('books an employee advance to its own account, and shows it outstanding', function () {
        asRole('finance_officer');
        $this->post(raUrl('payment-requests'), [
            'kind' => 'advance', 'account_id' => raAccount('1010'), 'amount' => '80', 'pay_on' => '2026-10-05', 'description' => 'Travel', 'payee' => 'A. Worker',
        ])->assertSessionHasNoErrors();
        $request = raRequest();

        asRole('finance_manager');
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertSessionHasNoErrors();
        asRole('cashier');
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasNoErrors();

        expect(raBalance('1250'))->toBe(8000);
        asRole('accountant');
        $this->get(raUrl())->assertInertia(fn (Assert $page) => $page->where('accounting.advances.outstandingMinor', 8000));
    });

    it('moves money between accounts through a request', function () {
        asRole('finance_officer');
        $this->post(raUrl('payment-requests'), [
            'kind' => 'transfer', 'account_id' => raAccount('1010'), 'to_account_id' => raAccount('1000'), 'amount' => '40', 'pay_on' => '2026-10-05', 'description' => 'Top up',
        ])->assertSessionHasNoErrors();
        $request = raRequest();

        asRole('finance_manager');
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertSessionHasNoErrors();
        asRole('cashier');
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasNoErrors();

        expect(raBalance('1000'))->toBe(4000)->and(raBalance('1010'))->toBe(-4000);
    });

    it('keeps other organizations out of a request', function () {
        asRole('finance_officer');
        raExpense()->assertSessionHasNoErrors();
        $request = raRequest();

        $this->platform()->organization('other');
        $this->setUpFinance('other');
        $this->actingAsMember('other', ['finance.payments.approve']);
        $this->post($this->workspaceUrl('other', "payment-requests/{$request->id}/approve"))->assertNotFound();
        expect(raRequest()->status)->toBe(PaymentRequest::PENDING_APPROVAL);
    });
});

describe('dashboards', function () {
    it('gives each role the dashboard its permissions name, drawn from the same records', function () {
        asRole('finance_manager');
        $this->get(raUrl())->assertInertia(fn (Assert $page) => $page
            ->where('views', ['finance'])
            ->where('view', 'finance')
            ->where('accounting', null)
            ->has('finance.queue')
            ->has('finance.cashFlow')
            ->has('finance.performance'));

        asRole('accountant');
        $this->get(raUrl())->assertInertia(fn (Assert $page) => $page
            ->where('views', ['accounting'])
            ->where('finance', null)
            ->has('accounting.journals')
            ->has('accounting.trialBalance')
            ->has('accounting.banks'));

        // Asking for the other one by hand gets nothing more.
        $this->get(raUrl('?view=finance'))->assertInertia(fn (Assert $page) => $page->where('view', 'accounting')->where('finance', null));

        asRole('auditor');
        $this->get(raUrl('?view=accounting'))->assertInertia(fn (Assert $page) => $page
            ->where('views', ['finance', 'accounting'])
            ->where('view', 'accounting'));

        asRole('system_administrator');
        $this->get(raUrl())->assertInertia(fn (Assert $page) => $page->where('views', [])->where('view', null));
    });

    it('only shows a role the figures its permissions allow', function () {
        asRole('cashier');
        $this->get(raUrl())->assertInertia(fn (Assert $page) => $page
            ->where('view', 'finance')
            ->has('finance.queue')
            ->where('finance.budget', null)
            ->where('finance.cashFlow', null)
            ->where('finance.performance', null)
            ->where('finance.departments', null));
    });

    it('reports budget use and departmental spending from the ledger', function () {
        asRole('chief_accountant');
        $this->post(raUrl('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12])->assertSessionHasNoErrors();
        $this->post(raUrl('dimensions'), ['kind' => DimensionValue::COST_CENTRE, 'code' => 'ADM', 'name' => 'Admin'])->assertSessionHasNoErrors();
        $centre = $this->asTenant('acme', fn () => DimensionValue::query()->sole());

        asRole('finance_manager');
        $year = $this->asTenant('acme', fn () => FiscalYear::query()->sole());
        $this->post(raUrl('budgets'), ['fiscal_year_id' => $year->id, 'name' => 'Plan'])->assertSessionHasNoErrors();
        $budget = $this->asTenant('acme', fn () => Budget::query()->sole());
        $this->asTenant('acme', fn () => BudgetLine::query()->create(['budget_id' => $budget->id, 'account_id' => Account::query()->where('code', '6000')->sole()->id, 'period' => 10, 'amount_minor' => 100000]));

        asRole('finance_officer');
        $this->post(raUrl('payment-requests'), [
            'kind' => 'expense', 'account_id' => raAccount('1010'), 'other_account_id' => raAccount('6000'), 'cost_centre_id' => $centre->id,
            'amount' => '250', 'pay_on' => '2026-10-05', 'description' => 'Rent',
        ])->assertSessionHasNoErrors();
        $request = raRequest();
        asRole('finance_manager');
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertSessionHasNoErrors();
        asRole('cashier');
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasNoErrors();

        asRole('finance_manager');
        $this->get(raUrl())->assertInertia(fn (Assert $page) => $page
            ->where('finance.budget.actualMinor', 25000)
            ->where('finance.budget.budgetMinor', 100000)
            ->where('finance.budget.percent', 25)
            ->where('finance.departments.0.minor', 25000)
            ->where('finance.cashFlow.netChangeMinor', -25000));
    });

    it('lists what stands between the books and closing the month', function () {
        asRole('chief_accountant');
        $this->post(raUrl('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12])->assertSessionHasNoErrors();
        foreach ($this->asTenant('acme', fn () => FiscalPeriod::query()->whereDate('starts_on', '<=', '2026-08-01')->orderBy('starts_on')->get()) as $month) {
            $this->post(raUrl("periods/{$month->id}/lock"))->assertSessionHasNoErrors();
        }

        $this->post(raUrl('journals'), ['entry_date' => '2026-09-05', 'memo' => 'Rent', 'lines' => [
            ['account_id' => raAccount('6000'), 'debit' => '10'],
            ['account_id' => raAccount('1010'), 'credit' => '10'],
        ]])->assertSessionHasNoErrors();

        $this->get(raUrl())->assertInertia(fn (Assert $page) => $page
            ->where('accounting.close.month', 'September 2026')
            ->where('accounting.close.tasks.3.key', 'reconciliation')
            ->where('accounting.close.tasks.3.open', 1));
    });

    it('shows petty cash apart from the banks', function () {
        $this->asTenant('acme', function () {
            $account = new Account(['code' => '1040', 'name' => 'Office float', 'type' => AccountType::Asset, 'bank_kind' => BankKind::PettyCash]);
            $account->save();
        });

        asRole('accountant');
        $this->get(raUrl())->assertInertia(fn (Assert $page) => $page
            ->has('accounting.pettyCash', 1)
            ->where('accounting.pettyCash.0.name', 'Office float'));
    });
});

describe('what the review found', function () {
    // Holds what is needed to prepare and pay in another currency.
    $dollars = function () {
        test()->actingAsMember('acme', [
            'finance.accounts.view', 'finance.accounts.manage', 'finance.currencies.manage', 'finance.banking.view', 'finance.banking.manage',
            'finance.payments.view', 'finance.payments.prepare',
        ], features: [Currencies::FEATURE]);
        test()->post(raUrl('accounts'), ['code' => '1050', 'name' => 'Dollar account', 'type' => 'asset', 'bank_kind' => 'bank', 'currency' => 'USD'])->assertRedirect()->assertSessionHasNoErrors();
    };
    $rate = fn (string $rate) => test()->post(raUrl('currencies/rates'), ['currency' => 'usd', 'rate_on' => '2026-10-01', 'rate' => $rate])->assertRedirect()->assertSessionHasNoErrors();
    $expense = fn (array $extra) => test()->post(raUrl('payment-requests'), $extra + [
        'kind' => 'expense', 'account_id' => raAccount('1050'), 'other_account_id' => raAccount('6000'), 'amount' => '600', 'pay_on' => '2026-10-05', 'description' => 'Dollar rent',
    ]);
    $taxCollected = function (string $debit, string $credit) {
        asRole('chief_accountant');
        test()->post(raUrl('journals'), ['entry_date' => '2026-09-10', 'memo' => 'Tax', 'lines' => [
            ['account_id' => raAccount($debit), 'debit' => '18'],
            ['account_id' => raAccount($credit), 'credit' => '18'],
        ]])->assertSessionHasNoErrors();
        test()->post(raUrl('tax-returns'), ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'])->assertRedirect()->assertSessionHasNoErrors();

        return test()->asTenant('acme', fn () => TaxReturn::query()->sole());
    };

    it('does not let a typed rate take a payment below the limit', function () use ($dollars, $rate, $expense) {
        raRules(['threshold' => '1000']);
        $dollars();
        $rate('2'); // USD 600 is 1,200 in the books: over the limit.

        $expense(['rate' => '1.5'])->assertSessionHasNoErrors();
        expect(raRequest()->status)->toBe(PaymentRequest::PENDING_APPROVAL)->and(raRequest()->books_minor)->toBe(120000);

        // Direct money out is judged the same way.
        $this->post(raUrl('banking/money-out'), [
            'account_id' => raAccount('1050'), 'other_account_id' => raAccount('6000'), 'amount' => '600', 'entry_date' => '2026-10-05', 'description' => 'Rent', 'rate' => '1.5',
        ])->assertSessionHasNoErrors()->assertRedirect(raUrl('payment-requests'));
        expect(raJournals())->toBe(0);
    });

    it('refuses a typed rate far from the one on record', function () use ($dollars, $rate, $expense) {
        raRules(['threshold' => '1000']);
        $dollars();
        $rate('2');

        $expense(['rate' => '0.0001'])->assertSessionHasErrors('rate');
        $expense(['rate' => '5'])->assertSessionHasErrors('rate');
        expect($this->asTenant('acme', fn () => PaymentRequest::query()->count()))->toBe(0);
    });

    it('always needs approval for a typed rate with nothing on record to check it against', function () use ($dollars, $expense) {
        raRules(['threshold' => '1000']);
        $dollars();

        $expense(['rate' => '0.5'])->assertSessionHasNoErrors();
        expect(raRequest()->status)->toBe(PaymentRequest::PENDING_APPROVAL);
    });

    it('ignores a rate typed on a transfer, which posts at the day\'s', function () use ($dollars, $rate) {
        raRules(['threshold' => '1000']);
        $dollars();
        $rate('2');

        $this->post(raUrl('payment-requests'), [
            'kind' => 'transfer', 'account_id' => raAccount('1050'), 'to_account_id' => raAccount('1010'), 'amount' => '600', 'received' => '1200', 'rate' => '0.0001', 'pay_on' => '2026-10-05', 'description' => 'Bring it home',
        ])->assertSessionHasNoErrors();

        expect(raRequest()->status)->toBe(PaymentRequest::PENDING_APPROVAL)->and(raRequest()->books_minor)->toBe(120000);
    });

    it('asks what arrives when a transfer crosses currencies, before it can be approved', function () use ($dollars, $rate) {
        raRules();
        $dollars();
        $rate('2');

        $this->post(raUrl('payment-requests'), [
            'kind' => 'transfer', 'account_id' => raAccount('1050'), 'to_account_id' => raAccount('1010'), 'amount' => '600', 'pay_on' => '2026-10-05', 'description' => 'Bring it home',
        ])->assertSessionHasErrors('received');

        expect($this->asTenant('acme', fn () => PaymentRequest::query()->count()))->toBe(0);
    });

    it('takes a bank-crediting manual journal through payment approval, after journal approval', function () {
        asRole('system_administrator');
        $this->put(raUrl('journal-approvals/settings'), ['journal_approval' => '1', 'threshold' => null])->assertRedirect()->assertSessionHasNoErrors();
        raRules();

        asRole('accountant');
        $this->post(raUrl('journals'), ['entry_date' => '2026-10-05', 'memo' => 'Rent by journal', 'lines' => [
            ['account_id' => raAccount('6000'), 'debit' => '100'],
            ['account_id' => raAccount('1010'), 'credit' => '100'],
        ]])->assertSessionHasNoErrors();

        // The journal approver passes it on; no money has moved.
        asRole('chief_accountant');
        $draft = $this->asTenant('acme', fn () => JournalDraft::query()->sole());
        $this->post(raUrl("journal-approvals/{$draft->id}/approve"))->assertRedirect()->assertSessionHasNoErrors();
        expect(raJournals())->toBe(0)->and(raBalance('1010'))->toBe(0)->and(raRequest()->kind)->toBe(PaymentRequest::JOURNAL);
        expect(raRequest()->status)->toBe(PaymentRequest::PENDING_APPROVAL);

        asRole('finance_manager');
        $this->post(raUrl('payment-requests/'.raRequest()->id.'/approve'))->assertRedirect()->assertSessionHasNoErrors();
        expect(raJournals())->toBe(0);

        asRole('cashier');
        $this->post(raUrl('payment-requests/'.raRequest()->id.'/disburse'), ['paid_on' => '2026-10-20'])->assertRedirect()->assertSessionHasNoErrors();
        expect(raJournals())->toBe(1)->and(raBalance('1010'))->toBe(-10000)->and(raBalance('6000'))->toBe(10000);
        expect(raRequest()->status)->toBe(PaymentRequest::DISBURSED);
    });

    it('holds the settlement of tax owed, once, until it is approved and disbursed', function () use ($taxCollected) {
        $return = $taxCollected('1010', '2200');
        raRules();

        asRole('chief_accountant');
        $settle = fn () => $this->post(raUrl("tax-returns/{$return->id}/settle"), ['account_id' => raAccount('1010'), 'date' => '2026-10-05']);
        $settle()->assertSessionHasNoErrors()->assertRedirect(raUrl('payment-requests'));
        $settle()->assertSessionHasErrors('return');
        expect(raBalance('1010'))->toBe(1800)->and(raRequest()->kind)->toBe(PaymentRequest::TAX);

        asRole('finance_manager');
        $this->post(raUrl('payment-requests/'.raRequest()->id.'/approve'))->assertRedirect()->assertSessionHasNoErrors();
        asRole('cashier');
        $this->post(raUrl('payment-requests/'.raRequest()->id.'/disburse'), ['paid_on' => '2026-10-20'])->assertRedirect()->assertSessionHasNoErrors();

        expect(raBalance('1010'))->toBe(0)
            ->and($this->asTenant('acme', fn () => TaxReturn::query()->sole()->settlement_journal_id))->not->toBeNull();
    });

    it('refuses to pay a settlement whose return was unfiled, leaving it to be rejected', function () use ($taxCollected) {
        $return = $taxCollected('1010', '2200');
        raRules();

        asRole('chief_accountant');
        $this->post(raUrl("tax-returns/{$return->id}/settle"), ['account_id' => raAccount('1010'), 'date' => '2026-10-05'])->assertRedirect()->assertSessionHasNoErrors();
        asRole('finance_manager');
        $this->post(raUrl('payment-requests/'.raRequest()->id.'/approve'))->assertRedirect()->assertSessionHasNoErrors();
        asRole('chief_accountant');
        $this->delete(raUrl("tax-returns/{$return->id}"))->assertRedirect();

        asRole('cashier');
        $this->post(raUrl('payment-requests/'.raRequest()->id.'/disburse'), ['paid_on' => '2026-10-20'])->assertSessionHasErrors('request');
        expect(raRequest()->status)->toBe(PaymentRequest::APPROVED)->and(raBalance('1010'))->toBe(1800);
    });

    it('does not unfile a return if that takes its refund back out of the bank while payments are held', function () use ($taxCollected) {
        $return = $taxCollected('2200', '1010');
        $this->post(raUrl("tax-returns/{$return->id}/settle"), ['account_id' => raAccount('1010'), 'date' => '2026-10-05'])->assertRedirect()->assertSessionHasNoErrors();
        raRules();

        asRole('chief_accountant');
        $this->delete(raUrl("tax-returns/{$return->id}"))->assertSessionHasErrors('return');
        expect($this->asTenant('acme', fn () => TaxReturn::query()->count()))->toBe(1);
    });

    it('holds the reversal of money that came in, but not of money that went out', function () {
        $this->actingAsMember('acme', ['finance.banking.view', 'finance.banking.manage', 'finance.journals.view']);
        $move = fn (string $path) => $this->post(raUrl($path), ['account_id' => raAccount('1010'), 'other_account_id' => raAccount('6000'), 'amount' => '50', 'entry_date' => '2026-10-05', 'description' => 'Move'])->assertRedirect()->assertSessionHasNoErrors();
        $move('banking/money-in');
        $in = $this->asTenant('acme', fn () => Journal::query()->where('source_type', 'money_in')->sole());
        $move('banking/money-out');
        $out = $this->asTenant('acme', fn () => Journal::query()->where('source_type', 'money_out')->sole());
        raRules();

        asRole('chief_accountant');
        $reverse = fn (Journal $journal) => $this->post(raUrl("journals/{$journal->id}/reverse"), ['entry_date' => '2026-10-10', 'reason' => 'Entered in error']);
        $reverse($in)->assertSessionHasNoErrors()->assertRedirect(raUrl('payment-requests'));
        $reverse($in)->assertSessionHasErrors('journal');
        expect(raRequest()->kind)->toBe(PaymentRequest::REVERSAL)
            ->and($this->asTenant('acme', fn () => $in->fresh()->reversed_by_id))->toBeNull();

        $reverse($out)->assertSessionHasNoErrors();
        expect($this->asTenant('acme', fn () => $out->fresh()->reversed_by_id))->not->toBeNull();

        asRole('finance_manager');
        $request = $this->asTenant('acme', fn () => PaymentRequest::query()->where('kind', PaymentRequest::REVERSAL)->sole());
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertRedirect()->assertSessionHasNoErrors();
        asRole('cashier');
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertRedirect()->assertSessionHasNoErrors();
        expect($this->asTenant('acme', fn () => $in->fresh()->reversed_by_id))->not->toBeNull();
    });

    it('does not let whoever prepared or reviewed a payment disburse it', function () {
        raRules(['payment_review' => '1']);
        $access = ['finance.payments.view', 'finance.payments.prepare', 'finance.payments.review', 'finance.payments.disburse'];

        $preparer = $this->platformUser();
        $this->actingAsMember('acme', $access, user: $preparer);
        raExpense()->assertSessionHasNoErrors();
        $request = raRequest();

        $reviewer = $this->platformUser();
        $this->actingAsMember('acme', $access, user: $reviewer);
        $this->post(raUrl("payment-requests/{$request->id}/review"))->assertRedirect()->assertSessionHasNoErrors();
        asRole('finance_manager');
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertRedirect()->assertSessionHasNoErrors();

        foreach ([$preparer, $reviewer] as $user) {
            $this->actingAsMember('acme', $access, user: $user);
            $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasErrors('request');
            $this->get(raUrl('payment-requests'))->assertInertia(fn (Assert $page) => $page->where('open.0.canDisburse', false));
        }
        expect(raJournals())->toBe(0);

        $this->actingAsMember('acme', $access, user: $this->platformUser());
        $this->get(raUrl('payment-requests'))->assertInertia(fn (Assert $page) => $page->where('open.0.canDisburse', true));
    });

    it('needs a payment approver for a recurring journal that takes money out of a bank, its reversal included', function () {
        raRules();
        asRole('accountant');
        $save = fn (string $name, string $debit, string $credit, bool $reverses) => $this->post(raUrl('recurring-journals'), [
            'name' => $name, 'memo' => $name, 'frequency' => RecurringJournal::MONTHLY, 'starts_on' => '2026-10-01', 'reverses' => $reverses ? '1' : '0',
            'lines' => [['account_id' => raAccount($debit), 'debit' => '300'], ['account_id' => raAccount($credit), 'credit' => '300']],
        ])->assertSessionHasNoErrors();
        $save('Pays out', '6000', '1010', false);
        $save('Reverses out', '1010', '2000', true);
        $save('Comes in', '1010', '2000', false);
        $template = fn (string $name) => $this->asTenant('acme', fn () => RecurringJournal::query()->where('name', $name)->sole());

        $this->artisan('finance:recurring')->assertSuccessful();
        expect($template('Comes in')->posted_count)->toBe(1)
            ->and($template('Pays out')->posted_count)->toBe(0)
            ->and($template('Reverses out')->posted_count)->toBe(0);

        // A journal approver does not clear these.
        asRole('chief_accountant');
        $this->post(raUrl('recurring-journals/'.$template('Pays out')->id.'/approve'))->assertSessionHasErrors('approve');
        $this->get(raUrl('recurring-journals'))->assertInertia(fn (Assert $page) => $page->where('templates.1.heldAsPayment', true)->where('templates.1.canApprove', false));

        asRole('finance_manager');
        $this->post(raUrl('recurring-journals/'.$template('Reverses out')->id.'/approve'))->assertRedirect()->assertSessionHasNoErrors();
        $this->artisan('finance:recurring')->assertSuccessful();
        expect($template('Reverses out')->posted_count)->toBe(1)->and($template('Pays out')->posted_count)->toBe(0);
    });

    it('asks for a payment approver again if payments start being held after a journal approval', function () {
        asRole('system_administrator');
        $this->put(raUrl('journal-approvals/settings'), ['journal_approval' => '1', 'threshold' => null])->assertRedirect()->assertSessionHasNoErrors();
        asRole('accountant');
        $this->post(raUrl('recurring-journals'), [
            'name' => 'Rent', 'memo' => 'Rent', 'frequency' => RecurringJournal::MONTHLY, 'starts_on' => '2026-11-01',
            'lines' => [['account_id' => raAccount('6000'), 'debit' => '300'], ['account_id' => raAccount('1010'), 'credit' => '300']],
        ])->assertSessionHasNoErrors();
        $template = $this->asTenant('acme', fn () => RecurringJournal::query()->sole());

        asRole('chief_accountant');
        $this->post(raUrl("recurring-journals/{$template->id}/approve"))->assertRedirect()->assertSessionHasNoErrors();
        raRules();

        asRole('chief_accountant');
        $this->get(raUrl('recurring-journals'))->assertInertia(fn (Assert $page) => $page->where('templates.0.awaitingApproval', true));
        asRole('finance_manager');
        $this->post(raUrl("recurring-journals/{$template->id}/approve"))->assertRedirect()->assertSessionHasNoErrors();
        asRole('accountant');
        $this->get(raUrl('recurring-journals'))->assertInertia(fn (Assert $page) => $page->where('templates.0.awaitingApproval', false));
    });

    it('gives whoever sets the rules a page of them, without what waits under them', function () {
        raRules(['threshold' => '250', 'payment_review' => '1']);
        asRole('system_administrator');

        $this->get(raUrl('approval-rules'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('approval-rules/index')
            ->where('payments.approval', true)
            ->where('payments.threshold', '250.00')
            ->where('payments.review', true)
            ->where('journals.enabled', false));
        $this->get(raUrl('payment-requests'))->assertForbidden();
        $this->get(raUrl('journal-approvals'))->assertForbidden();

        asRole('finance_manager');
        $this->get(raUrl('approval-rules'))->assertForbidden();
    });

    it('pays a request whose description fills the memo, and holds one for a long supplier name', function () {
        raRules();
        $direct = ['finance.banking.view', 'finance.banking.manage', 'finance.payables.view', 'finance.payables.manage'];
        $this->actingAsMember('acme', $direct);
        $this->post(raUrl('banking/money-out'), [
            'account_id' => raAccount('1010'), 'other_account_id' => raAccount('6000'), 'amount' => '100', 'entry_date' => '2026-10-05', 'description' => str_repeat('x', 255),
        ])->assertSessionHasNoErrors();
        $request = raRequest();

        asRole('finance_manager');
        $this->post(raUrl("payment-requests/{$request->id}/approve"))->assertRedirect()->assertSessionHasNoErrors();
        asRole('cashier');
        $this->post(raUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertRedirect()->assertSessionHasNoErrors();

        $memo = $this->asTenant('acme', fn () => Journal::query()->sole()->memo);
        expect(mb_strlen($memo))->toBe(255)->and($memo)->toEndWith("({$request->number})");

        $this->actingAsMember('acme', $direct);
        $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => str_repeat('n', 255)]));
        $this->post(raUrl('supplier-payments'), [
            'supplier_id' => $supplier->id, 'account_id' => raAccount('1010'), 'amount' => '100', 'paid_on' => '2026-10-05',
        ])->assertSessionHasNoErrors();
        expect(mb_strlen(raRequest()->description))->toBe(255);
    });

    it('reads the dashboard in the same number of queries however many banks there are', function () {
        asRole('chief_accountant');
        $this->get(raUrl('?view=accounting'))->assertOk();

        $queries = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(raUrl('?view=accounting'))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $before = $queries();
        expect($before)->toBeGreaterThan(5);

        foreach (['1051', '1052', '1053'] as $code) {
            $this->post(raUrl('accounts'), ['code' => $code, 'name' => "Bank {$code}", 'type' => 'asset', 'bank_kind' => 'bank'])->assertRedirect()->assertSessionHasNoErrors();
        }

        expect($queries())->toBe($before);
    });
});
