<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Journal;

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->publishEventKeys();
    $this->setUpFinance('acme');
});

function payrollBalance(string $systemKey): int
{
    return test()->asTenant('acme', function () use ($systemKey) {
        $account = Account::system($systemKey);
        $lines = $account->lines()->get();

        return $account->type->balance((int) $lines->sum('debit_minor'), (int) $lines->sum('credit_minor'));
    });
}

function payrollPosted(array $overrides = [])
{
    return test()->sendEvent('finance.payroll.posted', 'payroll:PR-00001:posted', $overrides + [
        'run_reference' => 'PR-00001',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'currency' => 'SSP',
        'headcount' => 2,
        'earnings_minor' => 15_000_000,
        'employer_contributions_minor' => 2_550_000,
        'income_tax_minor' => 1_165_000,
        'social_security_minor' => 3_750_000,
        'other_deductions_minor' => 0,
        'payable_minor' => 12_635_000,
    ], source: 'payroll');
}

it('posts an approved payroll as one journal, then its payment', function () {
    payrollPosted()->assertCreated();

    expect(payrollBalance(Account::SALARIES))->toBe(15_000_000)
        ->and(payrollBalance(Account::EMPLOYER_CONTRIBUTIONS))->toBe(2_550_000)
        ->and(payrollBalance(Account::PAYE))->toBe(1_165_000)
        ->and(payrollBalance(Account::SOCIAL_SECURITY_PAYABLE))->toBe(3_750_000)
        ->and(payrollBalance(Account::SALARIES_PAYABLE))->toBe(12_635_000);

    $this->asTenant('acme', function () {
        $journal = Journal::query()->sole();
        expect($journal->entry_date->toDateString())->toBe('2026-03-31')
            ->and($journal->source_app)->toBe('payroll')
            ->and($journal->source_reference)->toBe('PR-00001');
    });

    $this->sendEvent('finance.payroll.paid', 'payroll:PR-00001:paid', [
        'run_reference' => 'PR-00001', 'currency' => 'SSP', 'amount_minor' => 12_635_000, 'method' => 'bank_transfer',
    ], source: 'payroll')->assertCreated();

    expect(payrollBalance(Account::SALARIES_PAYABLE))->toBe(0)
        ->and(payrollBalance(Account::BANK))->toBe(-12_635_000);
});

it('refuses a payroll journal that does not balance, and per-person pay', function () {
    payrollPosted(['payable_minor' => 1])->assertUnprocessable();

    payrollPosted(['net_pay' => 100])->assertUnprocessable()->assertJsonPath('error', 'invalid_envelope');

    $this->asTenant('acme', fn () => expect(Journal::query()->count())->toBe(0));
});

it('adopts an older chart\'s Salaries and wages account', function () {
    $this->asTenant('acme', function () {
        // A chart from before Payroll: 6100 without a system key.
        Account::query()->where('code', '6100')->update(['system_key' => null]);
        Account::query()->where('code', '2300')->delete();
    });

    payrollPosted()->assertCreated();

    $this->asTenant('acme', function () {
        expect(Account::system(Account::SALARIES)->code)->toBe('6100')
            ->and(Account::system(Account::SALARIES_PAYABLE)->type)->toBe(AccountType::Liability);
    });
});
