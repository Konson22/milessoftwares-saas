<?php

use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use App\Models\RecurringJournal;
use Inertia\Testing\AssertableInertia as Assert;

const RECURRING = [
    'finance.accounts.view', 'finance.journals.view', 'finance.journals.post', 'finance.periods.manage',
];

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->setUpFinance('acme');
    $this->actingAsMember('acme', RECURRING);
});

function rjUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path);
}

function rjAccount(string $code): Account
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole());
}

/** Rent of 300: Dr 6000 Rent / Cr 1010 Bank. */
function rjSave(array $overrides = [], ?RecurringJournal $template = null)
{
    $data = $overrides + [
        'name' => 'Office rent',
        'memo' => 'Monthly rent',
        'frequency' => RecurringJournal::MONTHLY,
        'starts_on' => '2026-01-31',
        'lines' => [
            ['account_id' => rjAccount('6000')->id, 'debit' => '300'],
            ['account_id' => rjAccount('1010')->id, 'credit' => '300'],
        ],
    ];

    return $template === null
        ? test()->post(rjUrl('recurring-journals'), $data)
        : test()->put(rjUrl("recurring-journals/{$template->id}"), $data);
}

function rjTemplate(string $name = 'Office rent'): RecurringJournal
{
    return test()->asTenant('acme', fn () => RecurringJournal::query()->where('name', $name)->sole());
}

/** @return list<string> the dates of the template's journals */
function rjDates(string $sourceType = Journal::RECURRING): array
{
    return test()->asTenant('acme', fn () => Journal::query()->where('source_type', $sourceType)->orderBy('entry_date')->pluck('entry_date')->map(fn ($date) => $date->toDateString())->all());
}

it('refuses templates that would not make a valid journal', function () {
    rjSave(['lines' => [
        ['account_id' => rjAccount('6000')->id, 'debit' => '300'],
        ['account_id' => rjAccount('1010')->id, 'credit' => '200'],
    ]])->assertSessionHasErrors('lines');
    rjSave(['frequency' => 'daily'])->assertSessionHasErrors('frequency');
    rjSave(['ends_on' => '2026-01-01'])->assertSessionHasErrors('ends_on');

    rjSave()->assertSessionHasNoErrors();
    rjSave()->assertSessionHasErrors('name');

    expect(rjTemplate()->next_on->toDateString())->toBe('2026-01-31');
});

it('posts every occurrence due, in order and once, keeping month ends', function () {
    rjSave(['ends_on' => '2026-05-31'])->assertSessionHasNoErrors();

    $this->travelTo('2026-03-31 09:00');
    $this->artisan('finance:recurring')->assertSuccessful();
    $this->artisan('finance:recurring')->assertSuccessful();

    expect(rjDates())->toBe(['2026-01-31', '2026-02-28', '2026-03-31'])
        ->and(rjTemplate()->posted_count)->toBe(3)
        ->and(rjTemplate()->next_on->toDateString())->toBe('2026-04-30');

    // Up to the last date, then finished.
    $this->travelTo('2026-09-01 09:00');
    $this->post(rjUrl('recurring-journals/'.rjTemplate()->id.'/run'))->assertSessionHas('success', '2 journals posted.');
    expect(rjDates())->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'])
        ->and(rjTemplate()->next_on)->toBeNull();

    $journal = $this->asTenant('acme', fn () => Journal::query()->where('source_type', Journal::RECURRING)->orderBy('entry_date')->firstOrFail());
    expect($journal->recurring_journal_id)->toBe(rjTemplate()->id)
        ->and($journal->recurring_occurrence)->toBe(0)
        ->and($journal->memo)->toBe('Monthly rent');
});

it('reverses an accrual the next day', function () {
    rjSave(['name' => 'Audit fee accrual', 'reverses' => '1', 'frequency' => RecurringJournal::QUARTERLY, 'starts_on' => '2026-03-31', 'lines' => [
        ['account_id' => rjAccount('6900')->id, 'debit' => '1200'],
        ['account_id' => rjAccount('2500')->id, 'credit' => '1200'],
    ]])->assertSessionHasNoErrors();

    $this->travelTo('2026-07-15 09:00');
    $this->artisan('finance:recurring')->assertSuccessful();

    expect(rjDates())->toBe(['2026-03-31', '2026-06-30'])
        ->and(rjDates(Journal::RECURRING_REVERSAL))->toBe(['2026-04-01', '2026-07-01']);

    $accrued = $this->asTenant('acme', fn () => (int) rjAccount('2500')->lines()->sum('credit_minor') - (int) rjAccount('2500')->lines()->sum('debit_minor'));
    expect($accrued)->toBe(0);
});

it('stops at a locked period with the reason, and carries on once it is open', function () {
    $this->post(rjUrl('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12])->assertSessionHasNoErrors();
    rjSave(['starts_on' => '2026-01-15'])->assertSessionHasNoErrors();
    $january = $this->asTenant('acme', fn () => FiscalPeriod::query()->whereDate('starts_on', '2026-01-01')->sole());
    $this->post(rjUrl("periods/{$january->id}/lock"))->assertSessionHasNoErrors();

    $this->travelTo('2026-02-20 09:00');
    $this->artisan('finance:recurring')->assertSuccessful();
    expect(rjDates())->toBe([])
        ->and(rjTemplate()->last_error)->toContain('locked up to 31 Jan 2026');
    $this->post(rjUrl('recurring-journals/'.rjTemplate()->id.'/run'))->assertSessionHasErrors('run');

    $this->get(rjUrl('recurring-journals'))->assertInertia(fn (Assert $page) => $page
        ->component('recurring-journals/index')
        ->where('templates.0.lastError', fn (string $error) => str_contains($error, 'locked'))
        ->where('templates.0.due', true));

    $this->post(rjUrl("periods/{$january->id}/unlock"))->assertSessionHasNoErrors();
    $this->artisan('finance:recurring')->assertSuccessful();
    expect(rjDates())->toBe(['2026-01-15', '2026-02-15'])
        ->and(rjTemplate()->last_error)->toBeNull();
});

it('pauses and resumes, and keeps its schedule once posted', function () {
    rjSave()->assertSessionHasNoErrors();
    $this->post(rjUrl('recurring-journals/'.rjTemplate()->id.'/toggle'))->assertSessionHasNoErrors();

    $this->travelTo('2026-02-10 09:00');
    $this->artisan('finance:recurring')->assertSuccessful();
    expect(rjDates())->toBe([]);

    $this->post(rjUrl('recurring-journals/'.rjTemplate()->id.'/toggle'))->assertSessionHasNoErrors();
    $this->artisan('finance:recurring')->assertSuccessful();
    expect(rjDates())->toBe(['2026-01-31']);

    // The amount can change; the dates already posted cannot.
    rjSave(['starts_on' => '2026-01-01'], rjTemplate())->assertSessionHasErrors('starts_on');
    rjSave(['lines' => [
        ['account_id' => rjAccount('6000')->id, 'debit' => '350'],
        ['account_id' => rjAccount('1010')->id, 'credit' => '350'],
    ]], rjTemplate())->assertSessionHasNoErrors();
    expect(rjTemplate()->total())->toBe(35000)
        ->and(rjTemplate()->next_on->toDateString())->toBe('2026-02-28');

    $this->get(rjUrl('recurring-journals/'.rjTemplate()->id.'/edit'))->assertInertia(fn (Assert $page) => $page
        ->component('recurring-journals/form')
        ->where('template.lines.0.debit', '350.00')
        ->has('template.journals', 1));

    // Removing it keeps what it posted.
    $this->delete(rjUrl('recurring-journals/'.rjTemplate()->id))->assertRedirect();
    expect(rjDates())->toBe(['2026-01-31']);
});

it('keeps each organization to its own templates', function () {
    $this->platform()->organization('globex');
    $this->setUpFinance('globex');
    rjSave()->assertSessionHasNoErrors();

    $this->travelTo('2026-02-10 09:00');
    $this->artisan('finance:recurring')->assertSuccessful();

    expect(rjDates())->toBe(['2026-01-31'])
        ->and($this->asTenant('globex', fn () => Journal::query()->count()))->toBe(0);
});

it('lets members who only view journals see but not change them', function () {
    rjSave()->assertSessionHasNoErrors();
    $this->actingAsMember('acme', ['finance.journals.view']);

    $this->get(rjUrl('recurring-journals'))->assertOk();
    $this->get(rjUrl('recurring-journals/create'))->assertForbidden();
    $this->post(rjUrl('recurring-journals/'.rjTemplate()->id.'/run'))->assertForbidden();
    rjSave(['name' => 'Other'])->assertForbidden();
});
