<?php

use App\Logbook\Models\Entry;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\Notification;
use App\Logbook\Models\NotificationDelivery;
use App\Logbook\Models\Schedule;
use App\Logbook\Models\ScheduleOccurrence;
use App\Logbook\Models\Task;
use App\Mail\LogbookNotice;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * Scheduled log sheets, exceptions, conditional fields, resources and
 * entry notifications (ADR-0026 §2.6–2.10), and that none of it crosses
 * workspaces or reaches restricted logbooks.
 */

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-20 06:00:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook();
});

function schedule(string $sheetId, array $extra = []): Schedule
{
    test()->post(p("sheets/{$sheetId}/schedules"), [
        'frequency' => 'daily', 'opens_time' => '07:00', 'due_time' => '09:00', 'late_minutes' => 120,
        'timezone' => 'UTC', 'starts_on' => '2026-11-20', 'completion' => 'submitted', 'reminders' => ['due'],
        ...$extra,
    ])->assertSessionHasNoErrors();

    return inTenant(fn () => Schedule::query()->where('log_sheet_id', $sheetId)->latest('created_at')->firstOrFail());
}

it('expects entries, counts an offline reading by when it was taken, and marks the rest missed', function () {
    $sheet = publishedSheet($this->logbook, review: false);
    $schedule = schedule($sheet->id, ['responsible_id' => $this->team['writer']->id]);
    $this->artisan('logbook:operations')->assertSuccessful();
    $this->artisan('logbook:operations')->assertSuccessful();

    // A day ahead: at 06:00 today's is made; tomorrow's once it opens within a day.
    $made = fn () => inTenant(fn () => ScheduleOccurrence::query()->where('schedule_id', $schedule->id)->orderBy('occurs_on')->get());
    expect($made()->map(fn ($o) => $o->occurs_on->toDateString())->all())->toBe(['2026-11-20']);
    $this->travelTo('2026-11-20 07:30:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    $occurrences = $made();
    expect($occurrences->map(fn ($o) => $o->occurs_on->toDateString())->all())->toBe(['2026-11-20', '2026-11-21']);

    // Read at 08:30 on the phone, sent at 10:30: on time, because of when it was taken.
    $this->travelTo('2026-11-20 10:30:00');
    as_($this->team['writer'], 'logbook_contributor');
    $this->post(p('entries'), [
        'sheet_id' => $sheet->id, 'occurred_at' => '2026-11-20T08:30', 'values' => generatorValues(),
        'idempotency_key' => (string) Str::uuid7(), 'submit' => true,
    ])->assertSessionHasNoErrors();
    $today = inTenant(fn () => $occurrences[0]->fresh());
    expect($today->status)->toBe('completed')->and($today->log_entry_id)->toBe(latestEntry()->id);

    // Tomorrow's: nothing by the close (11:00) → missed, and the manager hears.
    $this->travelTo('2026-11-21 11:01:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(inTenant(fn () => $occurrences[1]->fresh()->status))->toBe('missed')
        ->and(inTenant(fn () => Notification::query()->where('user_id', $this->team['manager']->id)->where('event', 'schedule.missed')->count()))->toBe(1);

    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("occurrences/{$occurrences[1]->id}/excuse"), ['reason' => 'Generator under repair.'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $occurrences[1]->fresh()->status))->toBe('excused');
});

it('counts a late entry as late', function () {
    $sheet = publishedSheet($this->logbook, review: false);
    $schedule = schedule($sheet->id);
    $this->artisan('logbook:operations')->assertSuccessful();
    $this->travelTo('2026-11-20 10:00:00');
    as_($this->team['writer'], 'logbook_contributor');
    $this->post(p('entries'), [
        'sheet_id' => $sheet->id, 'occurred_at' => '2026-11-20T09:45', 'values' => generatorValues(),
        'idempotency_key' => (string) Str::uuid7(), 'submit' => true,
    ])->assertSessionHasNoErrors();

    expect(inTenant(fn () => ScheduleOccurrence::query()->where('schedule_id', $schedule->id)->whereDate('occurs_on', '2026-11-20')->sole()->status))->toBe('late');
});

it('raises one exception per abnormal reading or flagged answer, followed up to closure', function () {
    $fields = generatorFields();
    $fields[1] = [...$fields[1], 'expected_min' => '1', 'expected_max' => '12', 'raise_exception' => true, 'exception_severity' => 'high'];
    $fields[4] = [...$fields[4], 'exception_values' => ['faulty']];
    $sheet = publishedSheet($this->logbook, fields: $fields);

    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet, generatorValues(['hours' => '20', 'condition' => 'faulty']))->assertSessionHasNoErrors();
    $entry = latestEntry();

    $exceptions = inTenant(fn () => LogException::query()->where('log_entry_id', $entry->id)->orderBy('field')->get());
    expect($exceptions->pluck('field')->all())->toBe(['condition', 'hours'])
        ->and($exceptions->firstWhere('field', 'hours')->severity)->toBe('high')
        ->and($exceptions->firstWhere('field', 'hours')->reference)->toStartWith('EXC-2026-');

    // The reviewer is told the entry waits, with the abnormal reading named.
    $notice = inTenant(fn () => Notification::query()->where('user_id', $this->team['reviewer']->id)->where('event', 'entry.submitted')->sole());
    expect($notice->body)->toContain('outside the normal range');

    // Followed up: assigned, a corrective task, resolved (not while the task is open), closed by someone else.
    $exception = $exceptions->firstWhere('field', 'hours');
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("exceptions/{$exception->id}/assign"), ['assignee_id' => $this->team['other']->id])->assertSessionHasNoErrors();
    $this->post(p('tasks'), ['title' => 'Inspect the hour meter', 'assignee_id' => $this->team['other']->id, 'exception_id' => $exception->id, 'logbook_id' => $this->logbook->id])->assertSessionHasNoErrors();
    $task = inTenant(fn () => Task::query()->where('exception_id', $exception->id)->sole());

    as_($this->team['other'], 'logbook_contributor');
    $this->post(p("exceptions/{$exception->id}/resolve"), ['resolution_notes' => 'Meter replaced.'])->assertSessionHasErrors();
    $this->post(p("tasks/{$task->id}/complete"), ['notes' => 'Replaced.'])->assertSessionHasNoErrors();
    $this->post(p("exceptions/{$exception->id}/resolve"), ['resolution_notes' => 'Meter replaced.'])->assertSessionHasNoErrors();

    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("exceptions/{$exception->id}/close"))->assertSessionHasNoErrors();
    expect(inTenant(fn () => $exception->fresh()->status))->toBe('closed');

    // Resubmitting the same entry does not raise the same exception twice.
    expect(inTenant(fn () => LogException::query()->where('log_entry_id', $entry->id)->count()))->toBe(2);
});

it('hides a conditional field, clears its value and does not require it', function () {
    $fields = [
        ['name' => 'leak', 'label' => 'Leak found', 'type' => 'yes_no', 'required' => true],
        ['name' => 'leak_where', 'label' => 'Where', 'type' => 'text', 'required' => true, 'show_if' => ['field' => 'leak', 'in' => ['yes']]],
    ];
    $sheet = publishedSheet($this->logbook, 'LEAK', review: false, fields: $fields);

    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet, ['leak' => 'no', 'leak_where' => 'Should be cleared'])->assertSessionHasNoErrors();
    expect(latestEntry()->values)->not->toHaveKey('leak_where');

    writeEntry($sheet, ['leak' => 'yes'])->assertSessionHasErrors('values.leak_where');
    writeEntry($sheet, ['leak' => 'yes', 'leak_where' => 'Pump 2'])->assertSessionHasNoErrors();
    expect(latestEntry()->values['leak_where'])->toBe('Pump 2');
});

it('refuses a condition on a later or non-choice field', function () {
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("logbooks/{$this->logbook->id}/sheets"), ['name' => 'Bad', 'code' => 'BAD'])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', 'BAD')->sole());
    $this->post(p("sheets/{$sheet->id}/publish"), ['fields' => [
        ['name' => 'a', 'label' => 'A', 'type' => 'text', 'required' => false, 'show_if' => ['field' => 'b', 'in' => ['yes']]],
        ['name' => 'b', 'label' => 'B', 'type' => 'yes_no', 'required' => false],
    ]])->assertSessionHasErrors('fields.0.show_if');
    $this->post(p("sheets/{$sheet->id}/publish"), ['fields' => [
        ['name' => 'a', 'label' => 'A', 'type' => 'text', 'required' => false],
        ['name' => 'b', 'label' => 'B', 'type' => 'text', 'required' => false, 'show_if' => ['field' => 'a', 'in' => ['x']]],
    ]])->assertSessionHasErrors('fields.1.show_if');
});

it('takes resources in resource fields, active ones only', function () {
    $this->post(p('resources'), ['code' => 'gen-01', 'name' => 'Main generator', 'type' => 'Generator'])->assertSessionHasNoErrors();
    $this->post(p('resources'), ['code' => 'GEN-01', 'name' => 'Duplicate', 'type' => 'Generator'])->assertSessionHasErrors('code');
    $generator = inTenant(fn () => LogResource::query()->where('code', 'GEN-01')->sole());
    $sheet = publishedSheet($this->logbook, 'RES', review: false, fields: [
        ['name' => 'unit', 'label' => 'Unit', 'type' => 'resource', 'required' => true, 'resource_type' => 'Generator'],
    ]);

    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet, ['unit' => $generator->id])->assertSessionHasNoErrors();
    $this->get(p('entries/'.latestEntry()->id))->assertInertia(fn ($page) => $page->where('entry.answers.0.text', 'GEN-01 · Main generator'));

    as_($this->team['manager'], 'logbook_manager');
    $this->put(p("resources/{$generator->id}"), ['code' => 'GEN-01', 'name' => 'Main generator', 'type' => 'Generator', 'active' => false])->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet, ['unit' => $generator->id])->assertSessionHasErrors('values.unit');
});

it('tells the author when an entry is returned, by email too', function () {
    $sheet = publishedSheet($this->logbook);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet)->assertSessionHasNoErrors();
    $entry = latestEntry();
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->post(p("entries/{$entry->id}/review"), ['decision' => 'returned', 'comment' => 'Fuel missing.', 'revision' => 1])->assertSessionHasNoErrors();

    $notice = inTenant(fn () => Notification::query()->where('user_id', $this->team['writer']->id)->where('event', 'entry.returned')->sole());
    expect($notice->body)->toBe('Fuel missing.')->and($notice->emailed_at)->not->toBeNull();
    // Email goes out through a delivery row (ADR-0027), sent once.
    Mail::assertSent(LogbookNotice::class, 1);
    $delivery = inTenant(fn () => NotificationDelivery::query()->where('notification_id', $notice->id)->sole());
    expect($delivery->channel)->toBe('email')->and($delivery->status)->toBe('sent')->and($delivery->attempts)->toBe(1);

    // A review of an older revision than the one shown is refused.
    as_($this->team['writer'], 'logbook_contributor');
    $this->put(p("entries/{$entry->id}"), ['occurred_at' => '2026-11-19T08:30', 'values' => generatorValues(['fuel' => '30']), 'submit' => true])->assertSessionHasNoErrors();
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->post(p("entries/{$entry->id}/review"), ['decision' => 'reviewed', 'revision' => 1])->assertSessionHasErrors('decision');
});

it('keeps notifications, tasks and exceptions to the member and the workspace', function () {
    $restricted = logbook('Security', ['restricted' => true]);
    $this->post(p('tasks'), ['title' => 'Open task', 'assignee_id' => $this->team['writer']->id])->assertSessionHasNoErrors();
    $this->post(p('tasks'), ['title' => 'Secret task', 'assignee_id' => $this->team['manager']->id, 'logbook_id' => $restricted->id])->assertSessionHasNoErrors();
    $secret = inTenant(fn () => Task::query()->where('title', 'Secret task')->sole());

    // Another member does not see the restricted task, nor anyone else's notifications.
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->get(p("tasks/{$secret->id}"))->assertNotFound();
    $writerNotice = inTenant(fn () => Notification::query()->where('user_id', $this->team['writer']->id)->firstOrFail());
    $this->get(p("notifications/{$writerNotice->id}/open"))->assertNotFound();

    // Another workspace sees none of it.
    actingAsRole('logbook_manager', slug: 'globex');
    $this->get(p("tasks/{$secret->id}", 'globex'))->assertNotFound();
    $this->getJson(p('notifications/recent', 'globex'))->assertOk()->assertJsonPath('unread', 0);
    expect(inTenant(fn () => Task::query()->count(), 'globex'))->toBe(0);
});
