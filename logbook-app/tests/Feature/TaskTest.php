<?php

use App\Logbook\Models\Notification;
use App\Logbook\Models\Reminder;
use App\Logbook\Models\Task;
use App\Logbook\Models\TaskSeries;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Tasks (ADR-0026 §2.4–2.5): given by tasks.manage, worked by the assignee,
 * verified by someone else; reminders and notifications; recurring series
 * made idempotently by logbook:operations; the phone's completion is safe
 * to repeat and refused (409) once the task is no longer theirs.
 */

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->travelTo('2026-11-20 08:00:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook();
    $this->sheet = publishedSheet($this->logbook);
});

function giveTask(array $extra = []): Task
{
    test()->post(p('tasks'), [
        'title' => 'Check generator oil',
        'assignee_id' => test()->team['writer']->id,
        'logbook_id' => test()->logbook->id,
        'due_at' => '2026-11-20T17:00',
        'reminders' => ['before:60', 'due'],
        ...$extra,
    ])->assertSessionHasNoErrors();

    return inTenant(fn () => Task::query()->where('title', $extra['title'] ?? 'Check generator oil')->latest('created_at')->firstOrFail());
}

it('gives a task with a reference, reminders and a notification to the assignee', function () {
    $task = giveTask();

    expect($task->reference)->toBe('TASK-2026-000001')
        ->and($task->status)->toBe('pending')
        ->and(inTenant(fn () => Reminder::query()->where('subject_id', $task->id)->whereNull('sent_at')->pluck('kind')->sort()->values()->all()))->toBe(['before', 'due']);

    $notice = inTenant(fn () => Notification::query()->where('user_id', $this->team['writer']->id)->sole());
    expect($notice->event)->toBe('task.assigned')->and($notice->link)->toBe("tasks/{$task->id}");
    // Nobody is told about their own action.
    expect(inTenant(fn () => Notification::query()->where('user_id', $this->team['manager']->id)->count()))->toBe(0);
});

it('lets only task managers give tasks, and only to people who reach the logbook', function () {
    as_($this->team['writer'], 'logbook_contributor');
    $this->post(p('tasks'), ['title' => 'x', 'assignee_id' => $this->team['other']->id])->assertForbidden();

    as_($this->team['manager'], 'logbook_manager');
    $restricted = logbook('Restricted', ['restricted' => true]);
    $this->post(p('tasks'), ['title' => 'Secret', 'assignee_id' => $this->team['writer']->id, 'logbook_id' => $restricted->id])
        ->assertSessionHasErrors('assignee_id');
});

it('runs the lifecycle: start, complete, verify by someone else, or return', function () {
    $task = giveTask(['verification_required' => true]);

    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('tasks'))->assertInertia(fn ($page) => $page->component('tasks/index')->where('tasks.data.0.reference', $task->reference));
    $this->post(p("tasks/{$task->id}/start"), ['revision' => $task->revision])->assertSessionHasNoErrors();
    $this->post(p("tasks/{$task->id}/complete"), ['notes' => 'Topped up 2 l.'])->assertSessionHasNoErrors();
    $task = inTenant(fn () => $task->fresh());
    expect($task->status)->toBe('awaiting_verification');

    // The manager returns it with what to do; the assignee does it again; a reviewer verifies.
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("tasks/{$task->id}/verify"), ['decision' => 'returned'])->assertSessionHasErrors('comment');
    $this->post(p("tasks/{$task->id}/verify"), ['decision' => 'returned', 'comment' => 'Photo of the dipstick, please.'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $task->fresh()->status))->toBe('returned');

    as_($this->team['writer'], 'logbook_contributor');
    $this->post(p("tasks/{$task->id}/complete"), ['notes' => 'Photo added.'])->assertSessionHasNoErrors();
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->post(p("tasks/{$task->id}/verify"), ['decision' => 'verified'])->assertSessionHasNoErrors();

    $task = inTenant(fn () => $task->fresh());
    expect($task->status)->toBe('completed')->and($task->verified_by)->toBe($this->team['reviewer']->id);
    $this->get(p("tasks/{$task->id}"))->assertInertia(fn ($page) => $page->component('tasks/show')
        ->where('history', fn ($h) => collect($h)->pluck('action')->contains('task.verified')));
});

it('refuses work on a stale revision and by anyone but the assignee', function () {
    $task = giveTask();
    as_($this->team['other'], 'logbook_contributor');
    $this->post(p("tasks/{$task->id}/start"))->assertNotFound();

    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("tasks/{$task->id}/cancel"), ['reason' => 'Done by the vendor.', 'revision' => $task->revision + 5])->assertSessionHasErrors('stale');
    $this->post(p("tasks/{$task->id}/cancel"), ['reason' => 'Done by the vendor.', 'revision' => $task->revision])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $task->fresh()->status))->toBe('cancelled')
        ->and(inTenant(fn () => Reminder::query()->where('subject_id', $task->id)->whereNull('sent_at')->whereNull('cancelled_at')->count()))->toBe(0);
});

it('requires evidence when asked, then accepts it', function () {
    $task = giveTask(['evidence_required' => true]);
    as_($this->team['writer'], 'logbook_contributor');
    $this->post(p("tasks/{$task->id}/complete"))->assertSessionHasErrors('evidence');
    $this->post(p("tasks/{$task->id}/files"), ['kind' => 'evidence', 'file' => UploadedFile::fake()->image('oil.jpg')])->assertSessionHasNoErrors();
    $this->post(p("tasks/{$task->id}/complete"))->assertSessionHasNoErrors();
    expect(inTenant(fn () => $task->fresh()->status))->toBe('completed');
});

it('sends due reminders once, then marks overdue tasks to the creator', function () {
    $task = giveTask();
    $this->travelTo('2026-11-20 16:01:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(inTenant(fn () => Notification::query()->where('user_id', $this->team['writer']->id)->where('event', 'like', 'task.reminder.%')->count()))->toBe(1);

    $this->travelTo('2026-11-20 17:01:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(inTenant(fn () => Notification::query()->where('user_id', $this->team['writer']->id)->where('event', 'like', 'task.reminder.%')->count()))->toBe(2)
        ->and(inTenant(fn () => Task::query()->find($task->id)->overdue()))->toBeTrue();
});

it('makes recurring tasks a day ahead, never twice', function () {
    $this->post(p('task-series'), [
        'title' => 'Weekly fire extinguisher check',
        'assignee_id' => $this->team['writer']->id,
        'logbook_id' => $this->logbook->id,
        'frequency' => 'daily',
        'start_time' => '08:00',
        'due_time' => '12:00',
        'timezone' => 'UTC',
        'starts_on' => '2026-11-20',
    ])->assertSessionHasNoErrors();
    $series = inTenant(fn () => TaskSeries::query()->sole());

    $this->artisan('logbook:operations')->assertSuccessful();
    $this->artisan('logbook:operations')->assertSuccessful();
    $made = inTenant(fn () => Task::query()->where('series_id', $series->id)->orderBy('occurrence_on')->get());
    expect($made->map(fn ($t) => $t->occurrence_on->toDateString())->all())->toBe(['2026-11-20', '2026-11-21'])
        ->and($made->pluck('assignee_id')->unique()->all())->toBe([$this->team['writer']->id]);
});

it('takes the phone completion once, keyed, and refuses it after reassignment', function () {
    $task = giveTask();
    $this->actingAsAppMember('acme', rolePermissions('logbook_contributor'), user: $this->team['writer']);
    $key = (string) Str::uuid7();
    $api = fn (string $path) => p("api/v1/{$path}");

    $this->getJson($api('tasks'))->assertOk()->assertJsonPath('tasks.0.reference', $task->reference);
    $this->postJson($api("tasks/{$task->id}/complete"), ['key' => $key, 'completed_at' => '2026-11-20T07:45:00Z', 'notes' => 'Offline'])
        ->assertOk()->assertJsonPath('status', 'completed');
    $this->postJson($api("tasks/{$task->id}/complete"), ['key' => $key])->assertOk()->assertJsonPath('status', 'completed');
    expect(inTenant(fn () => $task->fresh()->completed_at->toIso8601String()))->toBe('2026-11-20T07:45:00+00:00');

    // Another task, reassigned while the phone was offline.
    as_($this->team['manager'], 'logbook_manager');
    $second = giveTask(['title' => 'Second']);
    $this->post(p("tasks/{$second->id}/assign"), ['assignee_id' => $this->team['other']->id])->assertSessionHasNoErrors();
    $this->actingAsAppMember('acme', rolePermissions('logbook_contributor'), user: $this->team['writer']);
    $this->postJson($api("tasks/{$second->id}/complete"), ['key' => (string) Str::uuid7()])->assertNotFound();
});
