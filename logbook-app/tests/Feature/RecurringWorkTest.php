<?php

use App\Logbook\Deliveries;
use App\Logbook\Models\Notification;
use App\Logbook\Models\NotificationDelivery;
use App\Logbook\Models\PushDevice;
use App\Logbook\Models\Reminder;
use App\Logbook\Models\Schedule;
use App\Logbook\Models\ScheduleOccurrence;
use App\Logbook\Models\Task;
use App\Logbook\Models\TaskSeries;
use App\Logbook\PushDevices;
use App\Logbook\Tasks;
use App\Mail\LogbookNotice;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * Recurring work and its delivery (ADR-0027): pausing, resuming and ending
 * recurring tasks and schedules, catching up after the clock was down,
 * timeliness apart from review, reminders that follow the work, one email
 * and one push per notification and channel with retries, push tokens,
 * personal choices, routes for the phone, and revoked access.
 */

beforeEach(function () {
    Mail::fake();

    config(['logbook.push.enabled' => false]);
    $this->travelTo('2026-11-20 06:00:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook();
});

function dailySeries(array $extra = []): TaskSeries
{
    test()->post(p('task-series'), [
        'title' => 'Morning generator round',
        'assignee_id' => test()->team['writer']->id,
        'logbook_id' => test()->logbook->id,
        'frequency' => 'daily',
        'start_time' => '08:00',
        'due_time' => '12:00',
        'timezone' => 'UTC',
        'starts_on' => '2026-11-20',
        ...$extra,
    ])->assertSessionHasNoErrors();

    return inTenant(fn () => TaskSeries::query()->latest('created_at')->firstOrFail());
}

function dailySchedule(string $sheetId, array $extra = []): Schedule
{
    test()->post(p("sheets/{$sheetId}/schedules"), [
        'frequency' => 'daily', 'opens_time' => '07:00', 'due_time' => '09:00', 'late_minutes' => 120,
        'timezone' => 'UTC', 'starts_on' => '2026-11-20', 'completion' => 'submitted', 'reminders' => ['due'],
        ...$extra,
    ])->assertSessionHasNoErrors();

    return inTenant(fn () => Schedule::query()->where('log_sheet_id', $sheetId)->latest('created_at')->firstOrFail());
}

function madeOn(TaskSeries $series): array
{
    return inTenant(fn () => Task::query()->where('series_id', $series->id)->orderBy('occurrence_on')->get()
        ->map(fn (Task $t) => $t->occurrence_on->toDateString().':'.$t->status)->all());
}

function oneTask(array $extra = []): Task
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

function entryAt($sheet, string $occurredAt): void
{
    test()->post(p('entries'), [
        'sheet_id' => $sheet->id, 'occurred_at' => $occurredAt, 'values' => generatorValues(),
        'idempotency_key' => (string) Str::uuid7(), 'submit' => true,
    ])->assertSessionHasNoErrors();
}

function onPhone(User $user, string $role): void
{
    test()->actingAsAppMember('acme', rolePermissions($role), user: $user);
    Cache::flush();
}

function expoOk(): void
{
    Http::fake([
        'exp.host/--/api/v2/push/send' => fn (HttpRequest $r) => Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => (string) Str::uuid()], $r->data())]),
    ]);
}

// ---- Recurring tasks ----

it('pauses, resumes and ends a recurring task without filling in the paused days', function () {
    $series = dailySeries();
    // A day ahead: at 06:00 today's; tomorrow's once it starts within a day.
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(madeOn($series))->toBe(['2026-11-20:pending']);
    $this->travelTo('2026-11-20 08:30:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(madeOn($series))->toBe(['2026-11-20:pending', '2026-11-21:pending']);

    // Paused: tomorrow's (not begun) is withdrawn, today's (begun) is left to finish, nothing new while paused.
    $this->post(p("task-series/{$series->id}/pause"))->assertSessionHasNoErrors();
    $this->travelTo('2026-11-23 06:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(madeOn($series))->toBe(['2026-11-20:pending', '2026-11-21:cancelled']);

    // Resumed: from now on only, so the 22nd is never made.
    $this->post(p("task-series/{$series->id}/resume"))->assertSessionHasNoErrors();
    $this->artisan('logbook:operations')->assertSuccessful();
    $this->travelTo('2026-11-23 09:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(madeOn($series))->toBe(['2026-11-20:pending', '2026-11-21:cancelled', '2026-11-23:pending', '2026-11-24:pending']);

    // Ended: tomorrow's is withdrawn, today's (begun) stays, nothing more is made, and it cannot be resumed.
    $this->post(p("task-series/{$series->id}/end"))->assertSessionHasNoErrors();
    $this->travelTo('2026-11-26 09:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(madeOn($series))->toBe(['2026-11-20:pending', '2026-11-21:cancelled', '2026-11-23:pending', '2026-11-24:cancelled']);
    $this->post(p("task-series/{$series->id}/resume"))->assertSessionHasErrors('series');
    $this->get(p('tasks?tab=recurring'))->assertInertia(fn ($page) => $page->where('series.0.state', 'ended')->where('series.0.nextDueAt', null));
});

it('keeps tasks already made as they were when the recurring task is changed', function () {
    $series = dailySeries();
    $this->artisan('logbook:operations')->assertSuccessful();
    $first = inTenant(fn () => Task::query()->where('series_id', $series->id)->orderBy('occurrence_on')->get());

    $this->put(p("task-series/{$series->id}"), [
        'title' => 'Morning generator round', 'assignee_id' => $this->team['other']->id, 'logbook_id' => $this->logbook->id,
        'frequency' => 'daily', 'start_time' => '09:00', 'due_time' => '13:00', 'timezone' => 'UTC', 'starts_on' => '2026-11-20',
    ])->assertSessionHasNoErrors();
    $this->travelTo('2026-11-22 06:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();

    // The 20th, made before the change, keeps its person and time; those made after follow the change.
    $all = inTenant(fn () => Task::query()->where('series_id', $series->id)->orderBy('occurrence_on')->get());
    expect($first)->toHaveCount(1)
        ->and($all->map(fn ($t) => $t->occurrence_on->toDateString().' '.($t->assignee_id === $this->team['writer']->id ? 'writer' : 'other').' '.$t->due_at->format('H:i'))->all())
        ->toBe(['2026-11-20 writer 12:00', '2026-11-21 other 13:00', '2026-11-22 other 13:00']);
});

it('makes recurring tasks in the time zone they were set in', function () {
    dailySeries(['timezone' => 'Africa/Juba', 'start_time' => '08:00', 'due_time' => '10:00']);
    $this->artisan('logbook:operations')->assertSuccessful();
    $task = inTenant(fn () => Task::query()->orderBy('occurrence_on')->firstOrFail());
    expect($task->starts_at->toIso8601String())->toBe('2026-11-20T06:00:00+00:00')
        ->and($task->due_at->toIso8601String())->toBe('2026-11-20T08:00:00+00:00');
});

// ---- Catching up, duplicate runs ----

it('catches up after the clock was down, once, however many runs overlap', function () {
    $series = dailySeries();
    $sheet = publishedSheet($this->logbook, review: false);
    $schedule = dailySchedule($sheet->id, ['responsible_id' => $this->team['writer']->id]);

    // Nothing ran for three days. A reading was written meanwhile on the 21st.
    $this->travelTo('2026-11-21 10:00:00');
    as_($this->team['writer'], 'logbook_contributor');
    entryAt($sheet, '2026-11-21T10:00');
    $this->travelTo('2026-11-23 10:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    $this->artisan('logbook:operations')->assertSuccessful();
    Tasks::generate(now()->toImmutable()); // a run that raced in

    expect(madeOn($series))->toBe(['2026-11-20:pending', '2026-11-21:pending', '2026-11-22:pending', '2026-11-23:pending', '2026-11-24:pending']);
    $occurrences = inTenant(fn () => ScheduleOccurrence::query()->where('schedule_id', $schedule->id)->orderBy('occurs_on')->get()
        ->map(fn ($o) => $o->occurs_on->toDateString().':'.$o->status)->all());
    expect($occurrences)->toBe(['2026-11-20:missed', '2026-11-21:late', '2026-11-22:missed', '2026-11-23:open', '2026-11-24:open']);
});

// ---- Scheduled log sheets ----

it('pauses and resumes a schedule without counting the paused days as missed', function () {
    $sheet = publishedSheet($this->logbook, review: false);
    $schedule = dailySchedule($sheet->id);
    $this->artisan('logbook:operations')->assertSuccessful();
    $this->post(p("schedules/{$schedule->id}/pause"))->assertSessionHasNoErrors();

    $this->travelTo('2026-11-23 06:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    $this->post(p("schedules/{$schedule->id}/resume"))->assertSessionHasNoErrors();
    $this->artisan('logbook:operations')->assertSuccessful();

    $days = inTenant(fn () => ScheduleOccurrence::query()->where('schedule_id', $schedule->id)->orderBy('occurs_on')->get()
        ->map(fn ($o) => $o->occurs_on->toDateString().':'.$o->status)->all());
    // Paused before the 20th opened: withdrawn. The 21st and 22nd were never expected; from the 23rd again.
    expect($days)->toBe(['2026-11-23:open']);
    $this->get(p('schedules'))->assertInertia(fn ($page) => $page->where('schedules.0.state', 'active'));

    $this->post(p("schedules/{$schedule->id}/end"))->assertSessionHasNoErrors();
    $this->get(p('schedules'))->assertInertia(fn ($page) => $page->where('schedules.0.state', 'ended'));
});

it('keeps an entry in on time as on time while it waits for review', function () {
    $sheet = publishedSheet($this->logbook, review: true);
    $schedule = dailySchedule($sheet->id, ['completion' => 'reviewed']);
    $this->artisan('logbook:operations')->assertSuccessful();

    $this->travelTo('2026-11-20 08:30:00');
    as_($this->team['writer'], 'logbook_contributor');
    entryAt($sheet, '2026-11-20T08:30');
    $occurrence = fn () => inTenant(fn () => ScheduleOccurrence::query()->where('schedule_id', $schedule->id)->whereDate('occurs_on', '2026-11-20')->sole());
    expect($occurrence()->status)->toBe('open')->and($occurrence()->timeliness)->toBe('on_time')->and($occurrence()->log_entry_id)->not->toBeNull();

    // Reviewed long after the late window: still on time, not missed.
    $this->travelTo('2026-11-20 15:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    expect($occurrence()->status)->toBe('open');
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->post(p('entries/'.latestEntry()->id.'/review'), ['decision' => 'reviewed', 'revision' => 1])->assertSessionHasNoErrors();
    expect($occurrence()->status)->toBe('completed')->and($occurrence()->timeliness)->toBe('on_time')->and($occurrence()->reviewed_at)->not->toBeNull();
});

it('opens the expected entry from its reminder and counts the entry against it', function () {
    $sheet = publishedSheet($this->logbook, review: false);
    $schedule = dailySchedule($sheet->id, ['responsible_id' => $this->team['writer']->id, 'reminders' => ['before:30']]);
    $this->artisan('logbook:operations')->assertSuccessful();
    $this->travelTo('2026-11-20 08:31:00');
    $this->artisan('logbook:operations')->assertSuccessful();

    $notice = inTenant(fn () => Notification::query()->where('user_id', $this->team['writer']->id)->where('event', 'schedule.reminder.before')->sole());
    $occurrence = inTenant(fn () => ScheduleOccurrence::query()->where('schedule_id', $schedule->id)->whereDate('occurs_on', '2026-11-20')->sole());
    expect($notice->link)->toBe("entries/new?sheet={$sheet->id}&occurrence={$occurrence->id}");

    onPhone($this->team['writer'], 'logbook_contributor');
    $this->getJson(p('api/v1/notifications'))->assertOk()
        ->assertJsonPath('notifications.0.route', ['screen' => 'record', 'sheet' => $sheet->id, 'occurrence' => $occurrence->id]);

    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p("entries/new?sheet={$sheet->id}&occurrence={$occurrence->id}"))->assertInertia(fn ($page) => $page->where('occurrence.id', $occurrence->id));
    $this->post(p('entries'), [
        'sheet_id' => $sheet->id, 'occurred_at' => '2026-11-20T08:40', 'values' => generatorValues(),
        'idempotency_key' => (string) Str::uuid7(), 'submit' => true, 'schedule_occurrence_id' => $occurrence->id,
    ])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $occurrence->fresh()->status))->toBe('completed')
        ->and(latestEntry()->schedule_occurrence_id)->toBe($occurrence->id);
});

// ---- Reminders ----

it('moves reminders with the due date, follows reassignment, and cancels them when the work is done', function () {
    $task = oneTask();
    $pending = fn () => inTenant(fn () => Reminder::query()->where('subject_id', $task->id)->whereNull('sent_at')->whereNull('cancelled_at')->orderBy('remind_at')->pluck('remind_at')->map->format('H:i')->all());
    expect($pending())->toBe(['16:00', '17:00']);

    $this->put(p("tasks/{$task->id}"), ['title' => 'Check generator oil', 'logbook_id' => $this->logbook->id, 'due_at' => '2026-11-20T18:00', 'reminders' => ['before:60', 'due']])->assertSessionHasNoErrors();
    expect($pending())->toBe(['17:00', '18:00']);

    $this->post(p("tasks/{$task->id}/assign"), ['assignee_id' => $this->team['other']->id])->assertSessionHasNoErrors();
    $this->travelTo('2026-11-20 17:01:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    $reminders = fn (User $u) => inTenant(fn () => Notification::query()->where('user_id', $u->id)->where('event', 'like', 'task.reminder.%')->count());
    expect($reminders($this->team['other']))->toBe(1)->and($reminders($this->team['writer']))->toBe(0);

    as_($this->team['other'], 'logbook_contributor');
    $this->post(p("tasks/{$task->id}/complete"), ['notes' => 'Done'])->assertSessionHasNoErrors();
    expect($pending())->toBe([]);
    $this->travelTo('2026-11-20 18:30:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    expect($reminders($this->team['other']))->toBe(1);
});

it('sends a reminder on the channels chosen for that work', function () {
    $task = oneTask(['reminders' => ['due'], 'reminder_channels' => ['email']]);
    $this->travelTo('2026-11-20 17:00:30');
    $this->artisan('logbook:operations')->assertSuccessful();

    $notice = inTenant(fn () => Notification::query()->where('subject_id', $task->id)->where('event', 'task.reminder.due')->sole());
    expect(inTenant(fn () => NotificationDelivery::query()->where('notification_id', $notice->id)->pluck('channel')->all()))->toBe(['email']);
    Mail::assertSent(LogbookNotice::class, fn (LogbookNotice $m) => str_contains($m->heading, 'is due now'));

    $this->post(p('tasks'), ['title' => 'x', 'assignee_id' => $this->team['writer']->id, 'reminder_channels' => ['sms']])->assertSessionHasErrors('reminder_channels.0');
});

// ---- Deliveries ----

it('sends each notification once per channel, however often the job runs or the queue misses it', function () {
    $task = oneTask();
    $notice = inTenant(fn () => Notification::query()->where('subject_id', $task->id)->where('event', 'task.assigned')->sole());
    $delivery = inTenant(fn () => NotificationDelivery::query()->where('notification_id', $notice->id)->sole());
    expect($delivery->status)->toBe('sent');

    // The job retried, the clock swept, an identical event repeated: still one email.
    inTenant(fn () => Deliveries::send($delivery->id));
    $this->travelTo('2026-11-20 06:10:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    Mail::assertSent(LogbookNotice::class, 1);

    // A delivery the queue never ran is picked up by the clock.
    $lost = inTenant(function () {
        $n = Notification::query()->create([
            'user_id' => $this->team['writer']->id, 'category' => 'task', 'event' => 'task.test', 'title' => 'Lost', 'subject_type' => 'task',
            'subject_id' => (string) Str::uuid7(), 'link' => 'tasks', 'dedupe_key' => 'lost', 'created_at' => now(),
        ]);

        return NotificationDelivery::query()->create(['notification_id' => $n->id, 'channel' => 'email']);
    });
    $this->travelTo('2026-11-20 06:15:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(inTenant(fn () => $lost->fresh()->status))->toBe('sent');
    Mail::assertSent(LogbookNotice::class, 2);
});

it('retries a push the service could not take, with backoff, then gives up', function () {
    config(['logbook.push.enabled' => true]);
    Http::fake(['exp.host/*' => Http::response('Busy', 503)]);
    inTenant(fn () => PushDevices::register($this->team['writer']->id, 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]', 'install-1', 'android', '1.0.0'));

    $task = oneTask();
    $push = fn () => inTenant(fn () => NotificationDelivery::query()->where('channel', 'push')->whereIn('notification_id', Notification::query()->where('subject_id', $task->id)->select('id'))->sole());
    expect($push()->status)->toBe('pending')->and($push()->attempts)->toBe(1)
        ->and($push()->next_attempt_at->toDateTimeString())->toBe('2026-11-20 06:01:00');

    foreach (['06:01:00', '06:06:00', '06:21:00', '07:21:00'] as $time) {
        $this->travelTo("2026-11-20 {$time}");
        $this->artisan('logbook:operations')->assertSuccessful();
    }
    expect($push()->status)->toBe('failed')->and($push()->attempts)->toBe(5)->and($push()->last_error)->toContain('503');
    // The in-app notification is there all along.
    expect(inTenant(fn () => Notification::query()->where('subject_id', $task->id)->count()))->toBe(1);
});

it('pushes a safe message with a route, and drops phones the service no longer knows', function () {
    config(['logbook.push.enabled' => true]);
    expoOk();
    onPhone($this->team['writer'], 'logbook_contributor');
    $this->postJson(p('api/v1/push-devices'), ['token' => 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]', 'install_id' => 'install-2', 'platform' => 'android'])
        ->assertCreated()->assertJsonPath('pushEnabled', true);

    as_($this->team['manager'], 'logbook_manager');
    $task = oneTask(['description' => 'Fuel reading 37 litres']);
    Http::assertSent(function (HttpRequest $r) use ($task) {
        $message = $r->data()[0] ?? [];

        return str_ends_with($r->url(), '/push/send')
            && $message['to'] === 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]'
            && $message['data']['route'] === ['screen' => 'task', 'id' => $task->id]
            && $message['data']['userId'] === $this->team['writer']->id
            && $message['channelId'] === 'work'
            && ! str_contains(json_encode($message), '37 litres');
    });

    // The receipt says the phone is gone: it is revoked and not pushed to again.
    Http::fake(['exp.host/--/api/v2/push/getReceipts' => fn (HttpRequest $r) => Http::response(['data' => collect($r->data()['ids'])
        ->mapWithKeys(fn ($id) => [$id => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]])->all()])]);
    $this->travelTo('2026-11-20 06:20:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(inTenant(fn () => PushDevice::query()->sole()->revoked_reason))->toBe('unregistered');
});

it('registers, refreshes, hands on and removes push tokens', function () {
    onPhone($this->team['writer'], 'logbook_contributor');
    $register = fn (string $token, string $install = 'install-3') => $this->postJson(p('api/v1/push-devices'), ['token' => $token, 'install_id' => $install, 'platform' => 'android', 'app_version' => '1.2.0']);
    $register('ExponentPushToken[cccccccccccccccccccccc]')->assertCreated();
    $register('ExponentPushToken[cccccccccccccccccccccc]')->assertOk();
    $register('not a token')->assertUnprocessable();

    // Expo refreshed the token on this install: the old one is replaced.
    $register('ExponentPushToken[dddddddddddddddddddddd]')->assertCreated();
    $devices = fn () => inTenant(fn () => PushDevice::query()->orderBy('created_at')->get(['user_id', 'revoked_reason']));
    expect($devices()->pluck('revoked_reason')->all())->toBe(['replaced', null]);

    // The phone is handed to someone else, who signs in: the token is theirs now.
    onPhone($this->team['other'], 'logbook_contributor');
    $register('ExponentPushToken[dddddddddddddddddddddd]')->assertOk();
    expect(inTenant(fn () => PushDevice::query()->whereNull('revoked_at')->sole()->user_id))->toBe($this->team['other']->id);

    // Signing out removes it.
    $this->postJson(p('api/v1/push-devices/remove'), ['install_id' => 'install-3'])->assertOk()->assertJsonPath('removed', 1);
    expect(inTenant(fn () => PushDevice::query()->whereNull('revoked_at')->count()))->toBe(0);
});

it('respects each person\'s email and push choices per category', function () {
    config(['logbook.push.enabled' => true]);
    expoOk();
    inTenant(fn () => PushDevices::register($this->team['writer']->id, 'ExponentPushToken[eeeeeeeeeeeeeeeeeeeeee]', 'install-4', 'android', null));

    as_($this->team['writer'], 'logbook_contributor');
    $choices = ['task' => false, 'entry' => true, 'schedule' => true, 'exception' => true];
    $this->put(p('notifications/settings'), ['email' => $choices, 'push' => $choices])->assertSessionHasNoErrors();
    $this->get(p('notifications'))->assertInertia(fn ($page) => $page->where('push.task', false)->where('email.entry', true)->where('phones', 1));

    as_($this->team['manager'], 'logbook_manager');
    $task = oneTask();
    $notice = inTenant(fn () => Notification::query()->where('subject_id', $task->id)->sole());
    expect(inTenant(fn () => NotificationDelivery::query()->where('notification_id', $notice->id)->count()))->toBe(0);
    Mail::assertNothingSent();
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'exp.host'));
});

// ---- Offline completion ----

it('keeps the phone\'s time and the server\'s receipt of an offline completion', function () {
    $task = oneTask();
    $this->travelTo('2026-11-20 10:00:00');
    onPhone($this->team['writer'], 'logbook_contributor');
    $key = (string) Str::uuid7();
    $this->postJson(p("api/v1/tasks/{$task->id}/complete"), ['key' => $key, 'completed_at' => '2026-11-20T07:45:00Z'])->assertOk()
        ->assertJsonPath('completionReceivedAt', fn ($v) => str_starts_with((string) $v, '2026-11-20'));
    $fresh = inTenant(fn () => $task->fresh());
    expect($fresh->completed_at->toIso8601String())->toBe('2026-11-20T07:45:00+00:00')
        ->and($fresh->completion_received_at->toIso8601String())->toBe('2026-11-20T10:00:00+00:00');

    // Sent again after a lost reply: the same completion, not a second one.
    $this->travelTo('2026-11-20 10:05:00');
    onPhone($this->team['writer'], 'logbook_contributor');
    $this->postJson(p("api/v1/tasks/{$task->id}/complete"), ['key' => $key, 'completed_at' => '2026-11-20T07:45:00Z'])->assertOk();
    expect(inTenant(fn () => $task->fresh()->completion_received_at->toIso8601String()))->toBe('2026-11-20T10:00:00+00:00');
});

// ---- Revoked access ----

it('stops notifying, delivering and syncing once someone loses access', function () {
    $task = oneTask(['reminders' => ['due']]);
    inTenant(function () {
        $n = Notification::query()->create([
            'user_id' => $this->team['writer']->id, 'category' => 'task', 'event' => 'task.test', 'title' => 'Before', 'subject_type' => 'task',
            'subject_id' => (string) Str::uuid7(), 'link' => 'tasks', 'dedupe_key' => 'before', 'created_at' => now(),
        ]);
        NotificationDelivery::query()->create(['notification_id' => $n->id, 'channel' => 'email']);
    });

    // Removed from the workspace's Logbook.
    $this->platform()->grant('acme', (string) $this->team['writer']->id, allowed: false);
    Cache::flush();

    $this->travelTo('2026-11-20 17:00:30');
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(inTenant(fn () => Notification::query()->where('user_id', $this->team['writer']->id)->where('event', 'task.reminder.due')->count()))->toBe(0)
        ->and(inTenant(fn () => Reminder::query()->where('subject_id', $task->id)->where('kind', 'due')->value('outcome')))->toBe('skipped: no access')
        ->and(inTenant(fn () => NotificationDelivery::query()->whereIn('notification_id', Notification::query()->where('dedupe_key', 'before')->select('id'))->value('last_error')))->toBe('no access');

    $this->withToken($this->platform()->appToken((string) $this->team['writer']->id));
    $this->getJson(p('api/v1/tasks'))->assertForbidden();
});

// ---- My Work ----

it('shows my next recurring task and the expected entries I missed on the home page', function () {
    $series = dailySeries();
    $sheet = publishedSheet($this->logbook, review: false);
    dailySchedule($sheet->id, ['responsible_id' => $this->team['writer']->id]);
    $this->artisan('logbook:operations')->assertSuccessful();
    $this->travelTo('2026-11-20 14:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();

    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p(''))->assertInertia(fn ($page) => $page
        ->where('operations.upcoming.0.id', $series->id)
        ->where('operations.upcoming.0.dueAt', '2026-11-21T12:00:00+00:00')
        ->where('operations.myMissed.0.sheet.id', $sheet->id)
        ->has('operations.returned', 0));

    // Only a task manager pauses it; once paused, nothing is coming up.
    $this->post(p("task-series/{$series->id}/pause"))->assertForbidden();
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("task-series/{$series->id}/pause"))->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p(''))->assertInertia(fn ($page) => $page->has('operations.upcoming', 0));
});
