<?php

use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Entry;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Logbook\Models\Schedule;
use App\Logbook\Recording;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * ADR-0030 phase 1: how a sheet is recorded is part of its template
 * version. Sheets from before keep their behaviour; one logbook holds
 * sheets of every mode; only scheduled sheets take schedules; the one
 * entry engine serves them all.
 */

beforeEach(function () {
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook('Equipment operations');
});

function sheetIn(string $code, ?string $mode): LogSheet
{
    test()->post(p('logbooks/'.test()->logbook->id.'/sheets'), ['name' => "{$code} log", 'code' => $code, 'review_required' => false])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', $code)->firstOrFail());
    test()->post(p("sheets/{$sheet->id}/publish"), ['fields' => generatorFields(), ...($mode ? ['recording' => ['mode' => $mode]] : [])])->assertSessionHasNoErrors();

    return inTenant(fn () => $sheet->fresh());
}

function scheduleOn(string $sheetId): TestResponse
{
    return test()->post(p("sheets/{$sheetId}/schedules"), [
        'frequency' => 'daily', 'opens_time' => '05:30', 'due_time' => '06:00', 'late_minutes' => 60,
        'timezone' => 'UTC', 'starts_on' => '2026-11-20', 'completion' => 'submitted',
    ]);
}

it('keeps different recording modes side by side in one logbook, on the one entry engine', function () {
    $operation = sheetIn('GOP', 'scheduled');
    $breakdown = sheetIn('GBD', 'event');
    $notes = sheetIn('OBS', null);

    expect(inTenant(fn () => [Recording::mode($operation), Recording::mode($breakdown), Recording::mode($notes)]))
        ->toBe(['scheduled', 'event', 'on_demand']);

    // Every mode writes the same entries: draft, submit, reference, revision.
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($operation)->assertSessionHasNoErrors();
    writeEntry($notes)->assertSessionHasNoErrors();
    // An event is recorded once it has ended (ADR-0030 §4.4); a client that sends no start is not held to it.
    $this->post(p('entries'), [
        'sheet_id' => $breakdown->id, 'occurred_at' => '2026-11-19T08:30', 'activity_started_at' => '2026-11-19T08:30',
        'values' => generatorValues(), 'idempotency_key' => (string) Str::uuid7(), 'submit' => true,
    ])->assertSessionHasErrors('activity_ended_at');
    writeEntry($breakdown)->assertSessionHasNoErrors();
    $this->post(p('entries'), [
        'sheet_id' => $breakdown->id, 'occurred_at' => '2026-11-19T08:30', 'activity_started_at' => '2026-11-19T08:30', 'activity_ended_at' => '2026-11-19T09:15',
        'values' => generatorValues(), 'idempotency_key' => (string) Str::uuid7(), 'submit' => true,
    ])->assertSessionHasNoErrors();
    $entries = inTenant(fn () => Entry::query()->orderBy('reference')->get());
    expect($entries)->toHaveCount(4)->and($entries->pluck('status')->unique()->all())->toBe(['finalized'])
        ->and($entries->pluck('revision')->unique()->all())->toBe([1]);

    $this->get(p("sheets/{$breakdown->id}"))->assertInertia(fn ($page) => $page->where('recordingMode', 'event'));
});

it('lets only scheduled sheets take schedules', function () {
    $operation = sheetIn('GOP', 'scheduled');
    $breakdown = sheetIn('GBD', 'event');
    $notes = sheetIn('OBS', 'on_demand');

    scheduleOn($operation->id)->assertSessionHasNoErrors();
    scheduleOn($breakdown->id)->assertSessionHasErrors('frequency');
    scheduleOn($notes->id)->assertSessionHasErrors('frequency');

    $this->get(p('schedules'))->assertInertia(fn ($page) => $page->where('options.schedulable', fn ($ids) => collect($ids)->sort()->values()->all() === [$operation->id]));
});

it('keeps sheets from before recording modes working as they did', function () {
    $legacy = sheetIn('OLD', null);
    scheduleOn($legacy->id)->assertSessionHasNoErrors();

    // No mode saved: a sheet with a schedule reads as scheduled.
    expect(inTenant(fn () => $legacy->publishedVersion->recording))->toBeNull()
        ->and(inTenant(fn () => Recording::mode($legacy->fresh())))->toBe('scheduled');
    $this->get(p("sheets/{$legacy->id}/template"))->assertInertia(fn ($page) => $page->where('recording.mode', 'scheduled')->where('recording.liveSchedules', true));
});

it('refuses to publish another mode while schedules still run, and keeps earlier versions as they were', function () {
    $sheet = sheetIn('GOP', 'scheduled');
    scheduleOn($sheet->id)->assertSessionHasNoErrors();
    $first = $sheet->published_version_id;

    $this->post(p("sheets/{$sheet->id}/publish"), ['fields' => generatorFields(), 'recording' => ['mode' => 'event']])->assertSessionHasErrors('recording.mode');

    $schedule = inTenant(fn () => Schedule::query()->sole());
    $this->post(p("schedules/{$schedule->id}/end"))->assertSessionHasNoErrors();
    $this->post(p("sheets/{$sheet->id}/publish"), ['fields' => generatorFields(), 'recording' => ['mode' => 'event']])->assertSessionHasNoErrors();

    $versions = inTenant(fn () => LogSheetVersion::query()->where('log_sheet_id', $sheet->id)->orderBy('version')->get());
    expect($versions->pluck('recording.mode')->all())->toBe(['scheduled', 'event'])
        ->and($versions[0]->id)->toBe($first);
    expect(inTenant(fn () => AuditEvent::query()->where('action', 'template.published')->latest('created_at')->first()->details['recording']))->toBe('event');
});

it('keeps the mode through a draft and refuses an unknown one', function () {
    $sheet = sheetIn('GBD', 'event');
    // Saving fields only does not change the mode.
    $this->put(p("sheets/{$sheet->id}/draft"), ['fields' => generatorFields()])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $sheet->fresh()->draftVersion->recording))->toBe(['mode' => 'event', 'shifts' => 'off']);
    $this->put(p("sheets/{$sheet->id}/draft"), ['fields' => generatorFields(), 'recording' => ['mode' => 'hourly']])->assertSessionHasErrors('recording.mode');
});

it('sends each sheet\'s mode to the phone', function () {
    $sheet = sheetIn('GBD', 'event');
    test()->actingAsAppMember('acme', rolePermissions('logbook_contributor'), user: $this->team['writer']);
    Cache::flush();
    $kit = $this->getJson($this->workspaceUrl('acme', 'api/v1/kit'))->assertOk()->json('sheets');
    expect(collect($kit)->firstWhere('id', $sheet->id)['recording'])->toBe(['mode' => 'event', 'shifts' => 'off', 'activity' => null, 'stops' => 'off']);
});

it('lets only managers set how a sheet is recorded', function () {
    $sheet = sheetIn('GOP', 'scheduled');
    as_($this->team['writer'], 'logbook_contributor');
    $this->post(p("sheets/{$sheet->id}/publish"), ['fields' => generatorFields(), 'recording' => ['mode' => 'event']])->assertForbidden();
    expect(inTenant(fn () => Recording::mode($sheet->fresh())))->toBe('scheduled');
});
