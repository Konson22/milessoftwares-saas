<?php

use App\Finance\Currencies;
use App\Models\Account;
use App\Models\Bill;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\Revaluation;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The books are in SSP (the fake platform's currency); documents and one bank in USD.
const FX_KEEPER = [
    'finance.accounts.view', 'finance.accounts.manage', 'finance.journals.view', 'finance.journals.post',
    'finance.payables.view', 'finance.payables.manage', 'finance.receivables.view', 'finance.receivables.manage',
    'finance.banking.view', 'finance.banking.manage', 'finance.reports.view', 'finance.currencies.manage',
];

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->publishEventKeys();
    $this->setUpFinance('acme');
    $this->actingAsMember('acme', FX_KEEPER, features: [Currencies::FEATURE]);
});

function fxUrl(string $path): string
{
    return test()->workspaceUrl('acme', $path);
}

function fxAccount(string $code): Account
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole());
}

function fxBalance(string $code): int
{
    return test()->asTenant('acme', function () use ($code) {
        $account = Account::query()->where('code', $code)->sole();

        return $account->type->balance((int) $account->lines()->sum('debit_minor'), (int) $account->lines()->sum('credit_minor'));
    });
}

/** The account's balance in its own currency. */
function fxForeignBalance(string $code): int
{
    return test()->asTenant('acme', fn () => (int) fxAccount($code)->lines()->sum('currency_minor'));
}

function fxRate(string $date, string $rate): void
{
    test()->post(fxUrl('currencies/rates'), ['currency' => 'usd', 'rate_on' => $date, 'rate' => $rate])->assertRedirect()->assertSessionHasNoErrors();
}

function fxDollarBank(): Account
{
    test()->post(fxUrl('accounts'), ['code' => '1050', 'name' => 'Dollar account', 'type' => 'asset', 'bank_kind' => 'bank', 'currency' => 'USD'])->assertSessionHasNoErrors();

    return fxAccount('1050');
}

function fxBooksBalance(): void
{
    test()->asTenant('acme', fn () => expect((int) JournalLine::query()->sum('debit_minor'))->toBe((int) JournalLine::query()->sum('credit_minor')));
}

it('needs the plan feature for anything in another currency', function () {
    $this->actingAsMember('acme', FX_KEEPER);

    $this->get(fxUrl('currencies'))->assertNotFound();
    $this->post(fxUrl('currencies/rates'), ['currency' => 'USD', 'rate_on' => '2026-10-01', 'rate' => '1000'])->assertNotFound();
    $this->post(fxUrl('invoices'), [
        'customer_name' => 'Aid agency', 'issued_on' => '2026-10-01', 'currency' => 'USD', 'rate' => '1000',
        'lines' => [['account_id' => fxAccount('4000')->id, 'description' => 'Supplies', 'amount' => '100']],
    ])->assertSessionHasErrors('currency');
    $this->get(fxUrl('invoices/create'))->assertInertia(fn (Assert $page) => $page->where('currencies', [])->where('multicurrency', false));

    // Without the feature an account cannot be put in another currency.
    $this->post(fxUrl('accounts'), ['code' => '1050', 'name' => 'Dollar account', 'type' => 'asset', 'bank_kind' => 'bank', 'currency' => 'USD'])->assertSessionHasNoErrors();
    expect(fxAccount('1050')->currency)->toBeNull();
});

it('keeps one rate per day, refuses the books\' own currency, and lists the latest', function () {
    fxRate('2026-10-01', '1000');
    fxRate('2026-10-01', '1050.5000');
    $this->post(fxUrl('currencies/rates'), ['currency' => 'USD', 'rate_on' => '2026-10-01', 'rate' => '1050.5'])->assertRedirect();
    fxRate('2026-10-10', '1100');

    $this->post(fxUrl('currencies/rates'), ['currency' => 'SSP', 'rate_on' => '2026-10-01', 'rate' => '1'])->assertSessionHasErrors('currency');
    $this->post(fxUrl('currencies/rates'), ['currency' => 'EUR', 'rate_on' => '2026-10-01', 'rate' => '0'])->assertSessionHasErrors('rate');

    expect($this->asTenant('acme', fn () => ExchangeRate::query()->count()))->toBe(2)
        ->and($this->asTenant('acme', fn () => app(Currencies::class)->rate('USD', CarbonImmutable::parse('2026-10-05'))))->toBe('1050.5');

    $this->get(fxUrl('currencies'))->assertInertia(fn (Assert $page) => $page
        ->component('currencies/index')
        ->where('currencies.0.currency', 'USD')
        ->where('currencies.0.latest.rate', '1100'));
    $this->get(fxUrl('invoices/create'))->assertInertia(fn (Assert $page) => $page->where('currencies', [['code' => 'USD', 'rate' => '1100', 'date' => '2026-10-10']]));
});

it('raises an invoice in dollars and posts the exchange difference when it is paid', function () {
    fxRate('2026-10-01', '1000');
    fxRate('2026-10-15', '1100');
    $dollars = fxDollarBank();

    $this->post(fxUrl('invoices'), [
        'customer_name' => 'Aid agency', 'issued_on' => '2026-10-01', 'currency' => 'USD',
        'lines' => [['account_id' => fxAccount('4000')->id, 'description' => 'Supplies', 'amount' => '100.00']],
    ])->assertSessionHasNoErrors();
    $invoice = $this->asTenant('acme', fn () => Invoice::query()->sole());
    expect($invoice->currency)->toBe('USD')
        ->and($invoice->currency_total_minor)->toBe(10000)
        ->and($invoice->total_minor)->toBe(10000000)
        ->and(fxBalance('1200'))->toBe(10000000)
        ->and(fxBalance('4000'))->toBe(10000000);

    // 60 dollars into the shilling bank at the day's 1,100: a 600,000 gain.
    $this->post(fxUrl("invoices/{$invoice->id}/payments"), ['account_id' => fxAccount('1010')->id, 'method' => 'bank_transfer', 'amount' => '60', 'received_on' => '2026-10-15'])->assertSessionHasNoErrors();
    expect(fxBalance('1010'))->toBe(6600000)
        ->and(fxBalance('1200'))->toBe(4000000)
        ->and(fxBalance('6950'))->toBe(-600000);

    // The rest into the dollar account at a typed 900: the remaining
    // receivable is cleared exactly, with a 400,000 loss.
    $this->post(fxUrl("invoices/{$invoice->id}/payments"), ['account_id' => $dollars->id, 'method' => 'bank_transfer', 'amount' => '40.01', 'received_on' => '2026-10-16', 'rate' => '900'])->assertSessionHasErrors('amount');
    $this->post(fxUrl("invoices/{$invoice->id}/payments"), ['account_id' => $dollars->id, 'method' => 'bank_transfer', 'amount' => '40', 'received_on' => '2026-10-16', 'rate' => '900'])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => $invoice->refresh()->status()))->toBe('paid')
        ->and($this->asTenant('acme', fn () => $invoice->currency_paid_minor))->toBe(10000)
        ->and(fxBalance('1200'))->toBe(0)
        ->and(fxBalance('1050'))->toBe(3600000)
        ->and(fxForeignBalance('1050'))->toBe(4000)
        ->and(fxBalance('6950'))->toBe(-200000);
    fxBooksBalance();

    // A dollar invoice is not received into another currency's account.
    $this->post(fxUrl('accounts'), ['code' => '1060', 'name' => 'Euro account', 'type' => 'asset', 'bank_kind' => 'bank', 'currency' => 'EUR'])->assertSessionHasNoErrors();
    $this->post(fxUrl('invoices'), [
        'customer_name' => 'Aid agency', 'issued_on' => '2026-10-16', 'currency' => 'USD',
        'lines' => [['account_id' => fxAccount('4000')->id, 'description' => 'More', 'amount' => '1']],
    ])->assertSessionHasNoErrors();
    $second = $this->asTenant('acme', fn () => Invoice::query()->where('id', '!=', $invoice->id)->sole());
    $this->post(fxUrl("invoices/{$second->id}/payments"), ['account_id' => fxAccount('1060')->id, 'method' => 'bank_transfer', 'amount' => '1', 'received_on' => '2026-10-16', 'rate' => '1'])->assertSessionHasErrors('account_id');
});

it('pays a dollar bill from the dollar account, with the loss on the rate', function () {
    fxRate('2026-10-01', '1000');
    fxRate('2026-10-15', '1100');
    $dollars = fxDollarBank();
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Importer']));

    // Shillings into dollars: 66,000 SSP becomes 60 USD at 1,100, no difference.
    $this->post(fxUrl('banking/transfers'), ['account_id' => fxAccount('1010')->id, 'to_account_id' => $dollars->id, 'amount' => '66000', 'entry_date' => '2026-10-15', 'description' => 'Buy dollars'])->assertSessionHasErrors('received');
    $this->post(fxUrl('banking/transfers'), ['account_id' => fxAccount('1010')->id, 'to_account_id' => $dollars->id, 'amount' => '66000', 'received' => '60', 'entry_date' => '2026-10-15', 'description' => 'Buy dollars'])->assertSessionHasNoErrors();
    expect(fxBalance('1050'))->toBe(6600000)->and(fxForeignBalance('1050'))->toBe(6000)->and(fxBalance('6950'))->toBe(0);

    $this->post(fxUrl('bills'), [
        'supplier_id' => $supplier->id, 'bill_date' => '2026-10-01', 'currency' => 'USD',
        'lines' => [['account_id' => fxAccount('5000')->id, 'description' => 'Stock', 'amount' => '50']],
    ])->assertSessionHasNoErrors();
    $bill = $this->asTenant('acme', fn () => Bill::query()->sole());
    expect($bill->total_minor)->toBe(5000000)->and(fxBalance('2000'))->toBe(5000000);

    $this->post(fxUrl('supplier-payments'), [
        'supplier_id' => $supplier->id, 'account_id' => $dollars->id, 'amount' => '50',
        'paid_on' => '2026-10-15', 'allocations' => [$bill->id => '50'],
    ])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => $bill->refresh()->status()))->toBe('paid')
        ->and(fxBalance('2000'))->toBe(0)
        ->and(fxForeignBalance('1050'))->toBe(1000)
        ->and(fxBalance('1050'))->toBe(1100000)
        ->and(fxBalance('6950'))->toBe(500000);
    fxBooksBalance();

    // Dollars out at a better rate than the books carry: the difference is a gain.
    $this->post(fxUrl('banking/transfers'), ['account_id' => $dollars->id, 'to_account_id' => fxAccount('1010')->id, 'amount' => '5', 'received' => '5600', 'entry_date' => '2026-10-15', 'description' => 'Sell dollars'])->assertSessionHasNoErrors();
    expect(fxBalance('6950'))->toBe(490000)->and(fxForeignBalance('1050'))->toBe(500);

    // The dollar account is posted to from Banking and documents only.
    $this->post(fxUrl('journals'), [
        'entry_date' => '2026-10-15', 'memo' => 'By hand',
        'lines' => [['account_id' => $dollars->id, 'debit' => '10'], ['account_id' => fxAccount('3000')->id, 'credit' => '10']],
    ])->assertSessionHasErrors();
    fxBooksBalance();
});

it('revalues open balances at a day\'s rate and reverses the next day', function () {
    fxRate('2026-10-01', '1000');
    $dollars = fxDollarBank();
    $this->post(fxUrl('banking/transfers'), ['account_id' => fxAccount('1010')->id, 'to_account_id' => $dollars->id, 'amount' => '10000', 'received' => '10', 'entry_date' => '2026-10-01', 'description' => 'Buy dollars'])->assertSessionHasNoErrors();
    $this->post(fxUrl('invoices'), [
        'customer_name' => 'Aid agency', 'issued_on' => '2026-10-01', 'currency' => 'USD',
        'lines' => [['account_id' => fxAccount('4000')->id, 'description' => 'Supplies', 'amount' => '20']],
    ])->assertSessionHasNoErrors();
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Importer']));
    $this->post(fxUrl('bills'), [
        'supplier_id' => $supplier->id, 'bill_date' => '2026-10-01', 'currency' => 'USD',
        'lines' => [['account_id' => fxAccount('5000')->id, 'description' => 'Stock', 'amount' => '10']],
    ])->assertSessionHasNoErrors();

    $this->get(fxUrl('currencies?as_of=2026-10-19'))->assertInertia(fn (Assert $page) => $page->where('previewError', fn ($error) => $error === null));
    fxRate('2026-10-19', '1200');
    $this->get(fxUrl('currencies?as_of=2026-10-19'))->assertInertia(fn (Assert $page) => $page
        ->has('preview', 3)
        ->where('preview.0.differenceMinor', 200000)   // 10 USD: 1,000,000 → 1,200,000
        ->where('preview.1.differenceMinor', 400000)   // 20 USD: 2,000,000 → 2,400,000
        ->where('preview.2.differenceMinor', 200000)); // owing 10 USD: a 200,000 loss

    $this->post(fxUrl('currencies/revaluations'), ['as_of' => '2026-10-19'])->assertSessionHasNoErrors();
    $this->post(fxUrl('currencies/revaluations'), ['as_of' => '2026-10-19'])->assertSessionHasErrors('as_of');
    $this->post(fxUrl('currencies/revaluations'), ['as_of' => '2099-01-01'])->assertSessionHasErrors('as_of');

    $revaluation = $this->asTenant('acme', fn () => Revaluation::query()->sole());
    [$journal, $reversal] = $this->asTenant('acme', fn () => [Journal::query()->findOrFail($revaluation->journal_id), Journal::query()->findOrFail($revaluation->reversal_journal_id)]);
    expect($journal->entry_date->toDateString())->toBe('2026-10-19')
        ->and($journal->source_type)->toBe(Journal::FX_REVALUATION)
        ->and($reversal->entry_date->toDateString())->toBe('2026-10-20')
        ->and($this->asTenant('acme', fn () => $journal->total()))->toBe(600000);

    // The net gain (bank and invoice less the bill) is in the books on the day, gone again the next, and the
    // money in dollars never moved.
    $fxOn = fn (string $date) => $this->asTenant('acme', fn () => (int) JournalLine::query()
        ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
        ->where('journal_lines.account_id', fxAccount('6950')->id)
        ->whereDate('journals.entry_date', '<=', $date)
        ->sum(DB::raw('journal_lines.debit_minor - journal_lines.credit_minor')));
    expect($fxOn('2026-10-19'))->toBe(-400000)
        ->and($fxOn('2026-10-20'))->toBe(0)
        ->and(fxForeignBalance('1050'))->toBe(1000)
        ->and(fxBalance('1050'))->toBe(1000000);
    fxBooksBalance();
});

it('keeps revaluation and rates to those who manage currencies', function () {
    $this->actingAsMember('acme', ['finance.reports.view'], features: [Currencies::FEATURE]);

    $this->get(fxUrl('currencies'))->assertOk();
    $this->post(fxUrl('currencies/rates'), ['currency' => 'USD', 'rate_on' => '2026-10-01', 'rate' => '1000'])->assertForbidden();
    $this->post(fxUrl('currencies/revaluations'), ['as_of' => '2026-10-01'])->assertForbidden();
});
