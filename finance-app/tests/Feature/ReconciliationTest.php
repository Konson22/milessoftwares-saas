<?php

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Journal;
use App\Models\JournalLine;
use Inertia\Testing\AssertableInertia as Assert;

const RECONCILER = [
    'finance.accounts.view', 'finance.journals.view', 'finance.journals.post',
    'finance.banking.view', 'finance.banking.manage', 'finance.banking.reconcile',
];

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->setUpFinance('acme');
    $this->actingAsMember('acme', RECONCILER);
});

function bankUrl(string $path): string
{
    return test()->workspaceUrl('acme', $path);
}

function bankAccount(string $code = '1010'): Account
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole());
}

/** A journal between the bank and another account: positive money in, negative money out. */
function bankEntry(string $date, string $amount, string $other = '4900', string $memo = 'Entry'): JournalLine
{
    $in = ! str_starts_with($amount, '-');
    $amount = ltrim($amount, '-');

    test()->post(bankUrl('journals'), [
        'entry_date' => $date,
        'memo' => $memo,
        'lines' => [
            ['account_id' => bankAccount($in ? '1010' : $other)->id, 'debit' => $amount],
            ['account_id' => bankAccount($in ? $other : '1010')->id, 'credit' => $amount],
        ],
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => JournalLine::query()
        ->where('account_id', bankAccount()->id)
        ->whereHas('journal', fn ($query) => $query->where('memo', $memo))
        ->latest('id')
        ->firstOrFail());
}

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $rows  date, description, amount
 */
function importStatement(string $endsOn, string $closing, array $rows, ?string $opening = '0', string $reference = 'ST-1')
{
    return test()->post(bankUrl('reconciliation'), [
        'account_id' => bankAccount()->id,
        'ends_on' => $endsOn,
        'reference' => $reference,
        'opening_balance' => $opening,
        'closing_balance' => $closing,
        'rows' => array_map(fn (array $row) => ['date' => $row[0], 'description' => $row[1], 'reference' => null, 'amount' => $row[2]], $rows),
        'mapping' => ['date' => '0', 'description' => '1', 'amount' => '2', 'dateFormat' => 'd/m/Y'],
    ]);
}

function statement(string $reference = 'ST-1'): BankStatement
{
    return test()->asTenant('acme', fn () => BankStatement::query()->where('reference', $reference)->sole());
}

function statementLine(string $description): BankStatementLine
{
    return test()->asTenant('acme', fn () => BankStatementLine::query()->where('description', $description)->sole());
}

it('imports statements one after the other, carrying the balance and skipping lines already imported', function () {
    importStatement('2026-10-31', '100', [['2026-10-03', 'Deposit', '150']], opening: null)->assertSessionHasErrors('opening_balance');
    importStatement('2026-10-31', '100', [['2026-11-03', 'Deposit', '150']])->assertSessionHasErrors('rows.0');

    importStatement('2026-10-31', '130', [
        ['2026-10-20', 'Charges', '-20'],
        ['2026-10-03', 'Deposit', '150'],
    ])->assertRedirect();

    $first = statement();
    expect($first->opening_balance_minor)->toBe(0)
        ->and($first->starts_on->toDateString())->toBe('2026-10-03')
        ->and($this->asTenant('acme', fn () => $first->lines()->pluck('description')->all()))->toBe(['Deposit', 'Charges'])
        ->and(bankAccount()->bank_import['dateFormat'])->toBe('d/m/Y');

    importStatement('2026-10-31', '0', [], reference: 'ST-2')->assertSessionHasErrors('ends_on');
    importStatement('2026-11-30', '180', [
        ['2026-10-20', 'Charges', '-20'],          // on the last statement: skipped
        ['2026-11-05', 'Transfer in', '50.00'],
    ], opening: '999', reference: 'ST-2')->assertSessionHas('success', 'Statement imported: 1 line; 1 already on the previous statement skipped.');

    $second = statement('ST-2');
    expect($second->opening_balance_minor)->toBe(13000)
        ->and($second->starts_on->toDateString())->toBe('2026-11-01');

    $this->get(bankUrl('reconciliation'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('statements', 2)
        ->where('banks.0.lastEndsOn', null)
        ->where('banks.1.lastEndsOn', '2026-11-30')
        ->where('banks.1.lastClosingMinor', 18000));
});

it('suggests and matches ledger lines of the same amount once, and protects matched journals from reversal', function () {
    $deposit = bankEntry('2026-10-02', '150', memo: 'Capital');
    bankEntry('2026-09-01', '150', memo: 'Too early');
    importStatement('2026-10-31', '150', [['2026-10-03', 'Deposit', '150']]);
    $line = statementLine('Deposit');

    $this->get(bankUrl('reconciliation/'.statement()->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('lines.0.candidates', 1)
            ->where('lines.0.candidates.0.id', $deposit->id));

    $this->post(bankUrl("reconciliation/lines/{$line->id}/match"), ['journal_line_id' => bankEntry('2026-10-04', '-150', '6000', 'Wrong way')->id])
        ->assertSessionHasErrors('line');
    $this->post(bankUrl("reconciliation/lines/{$line->id}/match"), ['journal_line_id' => $deposit->id])->assertSessionHasNoErrors();

    expect(statementLine('Deposit')->journal_line_id)->toBe($deposit->id);

    $this->post(bankUrl("journals/{$deposit->journal_id}/reverse"), ['entry_date' => '2026-10-10', 'reason' => 'Oops'])
        ->assertSessionHasErrors('journal');

    // A second statement line cannot clear the same ledger line.
    importStatement('2026-11-30', '300', [['2026-11-02', 'Deposit again', '150']], reference: 'ST-2');
    $this->post(bankUrl('reconciliation/lines/'.statementLine('Deposit again')->id.'/match'), ['journal_line_id' => $deposit->id])
        ->assertSessionHasErrors('line');

    $this->post(bankUrl("reconciliation/lines/{$line->id}/unmatch"))->assertSessionHasNoErrors();
    $this->post(bankUrl("journals/{$deposit->journal_id}/reverse"), ['entry_date' => '2026-10-10', 'reason' => 'Oops'])
        ->assertSessionHasNoErrors();
});

it('records a statement line in the books and matches it', function () {
    importStatement('2026-10-31', '-12.50', [['2026-10-15', 'SMS charges', '-12.50']]);

    $this->post(bankUrl('reconciliation/lines/'.statementLine('SMS charges')->id.'/enter'), ['account_id' => bankAccount('6300')->id])
        ->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        $journal = Journal::query()->sole();
        $line = BankStatementLine::query()->sole();

        expect($journal->source_type)->toBe('money_out')
            ->and($journal->entry_date->toDateString())->toBe('2026-10-15')
            ->and($line->journalLine->journal_id)->toBe($journal->id)
            ->and($line->journalLine->credit_minor)->toBe(1250);
    });
});

it('matches single candidates automatically and enters the rest by rules', function () {
    bankEntry('2026-10-04', '200', memo: 'Sale A');
    bankEntry('2026-10-09', '75', memo: 'Sale B');
    bankEntry('2026-10-10', '75', memo: 'Sale C');
    $this->post(bankUrl('bank-rules'), ['contains' => 'charge', 'direction' => 'out', 'account_id' => bankAccount('6300')->id, 'memo' => 'Bank charges'])
        ->assertSessionHasNoErrors();

    importStatement('2026-10-31', '257', [
        ['2026-10-05', 'Deposit 1', '200'],      // one candidate within 3 days
        ['2026-10-09', 'Deposit 2', '75'],       // two candidates: left alone
        ['2026-10-20', 'Monthly CHARGE', '-18'], // nothing in the books: the rule
        ['2026-10-21', 'Unknown', '-5'],         // nothing, no rule
    ]);

    $this->post(bankUrl('reconciliation/'.statement()->id.'/auto'))->assertSessionHas('success', '1 matched, 1 entered by rules.');

    $this->asTenant('acme', function () {
        expect(BankStatementLine::query()->whereNotNull('journal_line_id')->orderBy('position')->pluck('description')->all())->toBe(['Deposit 1', 'Monthly CHARGE'])
            ->and(Journal::query()->where('memo', 'Bank charges')->sole()->lines()->where('debit_minor', 1800)->sole()->account->code)->toBe('6300');
    });
});

it('reconciles a statement once every line is matched and it adds up, in order, and undoes it', function () {
    $a = bankEntry('2026-10-02', '150', memo: 'In');
    $b = bankEntry('2026-11-02', '40', memo: 'In again');
    importStatement('2026-10-31', '140', [['2026-10-03', 'Deposit', '150']]);
    importStatement('2026-11-30', '180', [['2026-11-03', 'Deposit 2', '40']], reference: 'ST-2');
    $first = statement();
    $second = statement('ST-2');

    $this->post(bankUrl("reconciliation/{$first->id}/reconcile"))->assertSessionHasErrors('statement');   // unmatched
    $this->post(bankUrl('reconciliation/lines/'.statementLine('Deposit')->id.'/match'), ['journal_line_id' => $a->id]);
    $this->post(bankUrl("reconciliation/{$first->id}/reconcile"))->assertSessionHasErrors('statement');   // 150 ≠ 140

    $this->post(bankUrl('reconciliation/lines/'.statementLine('Deposit 2')->id.'/match'), ['journal_line_id' => $b->id]);
    $this->post(bankUrl("reconciliation/{$second->id}/reconcile"))->assertSessionHasErrors('statement');  // earlier open

    // Typo in the first statement's closing balance: remove both and import again.
    $this->delete(bankUrl("reconciliation/{$first->id}"))->assertSessionHasErrors('statement');
    $this->delete(bankUrl("reconciliation/{$second->id}"))->assertSessionHasNoErrors();
    $this->delete(bankUrl("reconciliation/{$first->id}"))->assertSessionHasNoErrors();

    importStatement('2026-10-31', '150', [['2026-10-03', 'Deposit', '150']]);
    $this->post(bankUrl('reconciliation/lines/'.statementLine('Deposit')->id.'/match'), ['journal_line_id' => $a->id]);
    $this->post(bankUrl('reconciliation/'.statement()->id.'/reconcile'))->assertSessionHasNoErrors();

    expect(statement()->reconciled())->toBeTrue();
    $this->post(bankUrl('reconciliation/lines/'.statementLine('Deposit')->id.'/unmatch'))->assertSessionHasErrors('statement');

    $this->post(bankUrl('reconciliation/'.statement()->id.'/undo'))->assertSessionHasNoErrors();
    expect(statement()->reconciled())->toBeFalse();
});

it('reconciles the bank balance to the books', function () {
    bankEntry('2026-09-15', '1000', '3000', 'Before the statements');
    $deposit = bankEntry('2026-10-02', '150', memo: 'Cleared');
    bankEntry('2026-10-30', '60', memo: 'In transit');
    bankEntry('2026-10-31', '-25', '6000', 'Cheque not presented');

    importStatement('2026-10-31', '1150', [['2026-10-03', 'Deposit', '150']], opening: '1000');
    $this->post(bankUrl('reconciliation/lines/'.statementLine('Deposit')->id.'/match'), ['journal_line_id' => $deposit->id]);

    $this->get(bankUrl('reconciliation/'.statement()->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.statementMinor', 115000)
            ->where('report.depositsMinor', 6000)
            ->where('report.paymentsMinor', 2500)
            ->where('report.booksMinor', 118500)
            ->where('report.expectedMinor', 118500)
            ->where('report.differenceMinor', 0)
            ->has('report.uncleared', 2));
});

it('lets members without the reconcile permission look but not change', function () {
    importStatement('2026-10-31', '0', []);

    $this->actingAsMember('acme', ['finance.banking.view']);

    $this->get(bankUrl('reconciliation'))->assertOk();
    $this->get(bankUrl('reconciliation/'.statement()->id))->assertOk();
    $this->get(bankUrl('bank-rules'))->assertOk();
    importStatement('2026-11-30', '0', [], reference: 'ST-2')->assertForbidden();
    $this->post(bankUrl('reconciliation/'.statement()->id.'/reconcile'))->assertForbidden();
    $this->post(bankUrl('bank-rules'), ['contains' => 'x', 'direction' => 'any', 'account_id' => bankAccount('6300')->id])->assertForbidden();
});
