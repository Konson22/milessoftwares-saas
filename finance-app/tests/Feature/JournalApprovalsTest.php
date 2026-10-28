<?php

use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use App\Models\JournalDraft;
use App\Models\RecurringJournal;
use Inertia\Testing\AssertableInertia as Assert;

const PREPARER = ['finance.accounts.view', 'finance.journals.view', 'finance.journals.post'];

const APPROVER = ['finance.accounts.view', 'finance.journals.view', 'finance.journals.post', 'finance.journals.approve', 'finance.periods.manage'];

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->setUpFinance('acme');
    $this->approver = $this->actingAsMember('acme', APPROVER);
    $this->preparer = $this->actingAsMember('acme', PREPARER);
});

function jaUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path);
}

function jaAccount(string $code): string
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole()->id);
}

/** Rent paid from the bank: the response of entering it. */
function jaRent(string $amount = '300', string $date = '2026-10-05')
{
    return test()->post(jaUrl('journals'), [
        'entry_date' => $date,
        'memo' => 'October rent',
        'lines' => [
            ['account_id' => jaAccount('6000'), 'debit' => $amount],
            ['account_id' => jaAccount('1010'), 'credit' => $amount],
        ],
    ]);
}

function jaDraft(): JournalDraft
{
    return test()->asTenant('acme', fn () => JournalDraft::query()->orderByDesc('number')->firstOrFail());
}

function jaJournals(): int
{
    return test()->asTenant('acme', fn () => Journal::query()->count());
}

function jaAs(string $who): void
{
    test()->actingAsMember('acme', $who === 'approver' ? APPROVER : PREPARER, user: test()->{$who});
}

function jaTurnOn(?string $threshold = null): void
{
    jaAs('approver');
    test()->put(jaUrl('journal-approvals/settings'), ['journal_approval' => '1', 'threshold' => $threshold])->assertSessionHasNoErrors();
}

it('posts manual journals straight away while approval is off', function () {
    jaRent()->assertSessionHasNoErrors()->assertRedirect();

    expect(jaJournals())->toBe(1)
        ->and($this->asTenant('acme', fn () => JournalDraft::query()->count()))->toBe(0);
});

it('holds a journal out of the books until someone else approves it', function () {
    jaTurnOn();
    jaAs('preparer');

    jaRent()->assertSessionHasNoErrors()->assertRedirect(jaUrl('journal-approvals'));
    expect(jaJournals())->toBe(0)->and(jaDraft()->status)->toBe(JournalDraft::PENDING);

    // Without the permission, no approving.
    $this->post(jaUrl('journal-approvals/'.jaDraft()->id.'/approve'))->assertForbidden();
    expect(jaJournals())->toBe(0);

    jaAs('approver');
    $this->get(jaUrl('journal-approvals'))->assertInertia(fn (Assert $page) => $page
        ->component('journal-approvals/index')
        ->has('pending', 1)
        ->where('pending.0.own', false)
        ->where('pending.0.totalMinor', 30000)
        ->where('pending.0.lines.0.account', '6000 Rent'));
    $this->post(jaUrl('journal-approvals/'.jaDraft()->id.'/approve'))->assertSessionHasNoErrors();

    $journal = $this->asTenant('acme', fn () => Journal::query()->sole());
    expect($journal->entry_date->toDateString())->toBe('2026-10-05')
        ->and($journal->created_by)->toBe($this->preparer->getAuthIdentifier())
        ->and($journal->approved_by)->toBe($this->approver->getAuthIdentifier())
        ->and($journal->source_reference)->toBe(jaDraft()->number)
        ->and(jaDraft()->status)->toBe(JournalDraft::APPROVED)
        ->and(jaDraft()->journal_id)->toBe($journal->id);

    // Once only.
    $this->post(jaUrl('journal-approvals/'.jaDraft()->id.'/approve'))->assertSessionHasErrors('draft');
    expect(jaJournals())->toBe(1);

    $this->get(jaUrl("journals/{$journal->id}"))->assertInertia(fn (Assert $page) => $page->where('journal.approvedBy', $this->approver->getAttribute('name')));

    // An approver's own entry waits for someone else too.
    jaRent('40')->assertSessionHasNoErrors();
    $this->get(jaUrl('journal-approvals'))->assertInertia(fn (Assert $page) => $page->where('pending.0.own', true));
    $this->post(jaUrl('journal-approvals/'.jaDraft()->id.'/approve'))->assertSessionHasErrors('draft');
    $this->post(jaUrl('journal-approvals/'.jaDraft()->id.'/reject'), ['reason' => 'Mine'])->assertSessionHasErrors('draft');
    expect(jaJournals())->toBe(1)->and(jaDraft()->status)->toBe(JournalDraft::PENDING);
});

it('holds only journals from the threshold up', function () {
    jaTurnOn('1000');
    jaAs('preparer');

    jaRent('999.99')->assertSessionHasNoErrors();
    jaRent('1000')->assertSessionHasNoErrors();

    expect(jaJournals())->toBe(1)
        ->and(jaDraft()->total_minor)->toBe(100000);
});

it('lets the approver reject and the preparer withdraw', function () {
    jaTurnOn();
    jaAs('preparer');
    jaRent()->assertSessionHasNoErrors();
    $first = jaDraft();
    $this->travel(1)->seconds();
    jaRent('50')->assertSessionHasNoErrors();
    $second = jaDraft();

    // The approver cannot withdraw someone else's; the preparer cannot reject.
    jaAs('approver');
    $this->post(jaUrl("journal-approvals/{$second->id}/withdraw"))->assertSessionHasErrors('draft');
    $this->post(jaUrl("journal-approvals/{$first->id}/reject"), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(jaUrl("journal-approvals/{$first->id}/reject"), ['reason' => 'Paid by card, not the bank'])->assertSessionHasNoErrors();

    jaAs('preparer');
    $this->post(jaUrl("journal-approvals/{$first->id}/reject"), ['reason' => 'x'])->assertForbidden();
    $this->post(jaUrl("journal-approvals/{$second->id}/withdraw"))->assertSessionHasNoErrors();

    $statuses = $this->asTenant('acme', fn () => JournalDraft::query()->orderBy('number')->pluck('status')->all());
    expect($statuses)->toBe([JournalDraft::REJECTED, JournalDraft::WITHDRAWN])
        ->and(jaJournals())->toBe(0);

    // Decided drafts are final.
    jaAs('approver');
    $this->post(jaUrl("journal-approvals/{$first->id}/approve"))->assertSessionHasErrors('draft');
});

it('refuses a draft that could not post, and keeps one waiting when its month is locked', function () {
    jaTurnOn();
    $this->post(jaUrl('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12])->assertSessionHasNoErrors();
    jaAs('preparer');

    // Unbalanced.
    $this->post(jaUrl('journals'), ['entry_date' => '2026-10-05', 'memo' => 'Wrong', 'lines' => [
        ['account_id' => jaAccount('6000'), 'debit' => '10'],
        ['account_id' => jaAccount('1010'), 'credit' => '9'],
    ]])->assertSessionHasErrors('lines');
    jaRent()->assertSessionHasNoErrors();

    // Locked while it waited: approving fails, and it stays waiting.
    jaAs('approver');
    $months = $this->asTenant('acme', fn () => FiscalPeriod::query()->whereDate('starts_on', '<=', '2026-10-01')->orderBy('starts_on')->get());
    foreach ($months as $month) {
        $this->post(jaUrl("periods/{$month->id}/lock"))->assertSessionHasNoErrors();
    }
    $this->post(jaUrl('journal-approvals/'.jaDraft()->id.'/approve'))->assertSessionHasErrors('entry_date');
    expect(jaDraft()->status)->toBe(JournalDraft::PENDING)->and(jaJournals())->toBe(0);

    // Entering one into a locked month is refused at once.
    jaAs('preparer');
    jaRent(date: '2026-09-30')->assertSessionHasErrors('entry_date');
});

it('posts a recurring journal only once someone other than its last editor approves it', function () {
    jaTurnOn();
    jaAs('preparer');
    $this->post(jaUrl('recurring-journals'), [
        'name' => 'Office rent', 'memo' => 'Monthly rent', 'frequency' => RecurringJournal::MONTHLY, 'starts_on' => '2026-01-31',
        'lines' => [['account_id' => jaAccount('6000'), 'debit' => '300'], ['account_id' => jaAccount('1010'), 'credit' => '300']],
    ])->assertSessionHasNoErrors();
    $template = $this->asTenant('acme', fn () => RecurringJournal::query()->sole());

    $this->travelTo('2026-02-10 09:00');
    $this->artisan('finance:recurring')->assertSuccessful();
    expect(jaJournals())->toBe(0);
    $this->post(jaUrl("recurring-journals/{$template->id}/run"))->assertSessionHasErrors('run');

    jaAs('approver');
    $this->get(jaUrl('recurring-journals'))->assertInertia(fn (Assert $page) => $page->where('templates.0.awaitingApproval', true)->where('templates.0.ownChange', false));
    $this->get(jaUrl('journal-approvals'))->assertInertia(fn (Assert $page) => $page->where('recurringAwaiting', 1));
    $this->post(jaUrl("recurring-journals/{$template->id}/approve"))->assertSessionHasNoErrors();
    $this->artisan('finance:recurring')->assertSuccessful();
    expect(jaJournals())->toBe(1);

    // Changed by the approver: approved again by someone else (not them).
    $this->put(jaUrl("recurring-journals/{$template->id}"), [
        'name' => 'Office rent', 'memo' => 'Monthly rent', 'frequency' => RecurringJournal::MONTHLY, 'starts_on' => '2026-01-31',
        'lines' => [['account_id' => jaAccount('6000'), 'debit' => '350'], ['account_id' => jaAccount('1010'), 'credit' => '350']],
    ])->assertSessionHasNoErrors();
    $this->post(jaUrl("recurring-journals/{$template->id}/approve"))->assertSessionHasErrors('approve');
    $this->travelTo('2026-03-31 09:00');
    $this->artisan('finance:recurring')->assertSuccessful();
    expect(jaJournals())->toBe(1);
});

it('lets only approvers change the setting', function () {
    $this->put(jaUrl('journal-approvals/settings'), ['journal_approval' => '1'])->assertForbidden();
    $this->get(jaUrl('journal-approvals'))->assertOk();

    jaTurnOn('250.50');
    $this->get(jaUrl('journal-approvals'))->assertInertia(fn (Assert $page) => $page->where('setting.enabled', true)->where('setting.threshold', '250.50'));
    $this->put(jaUrl('journal-approvals/settings'), ['journal_approval' => '0', 'threshold' => '100'])->assertSessionHasNoErrors();
    $this->get(jaUrl('journals/create'))->assertInertia(fn (Assert $page) => $page->where('approval', null));
});
