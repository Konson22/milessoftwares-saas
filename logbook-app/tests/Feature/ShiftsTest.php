<?php

use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\EntryRevision;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\Shift;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 * ADR-0030 phase 3: optional shifts. Kept by managers; never overlapping
 * in one logbook; named by entries on sheets that ask for them; kept on
 * every revision; a report filter. Not rostering or attendance.
 */

beforeEach(function () {
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook('Equipment operations');
});

function shiftSheet(string $code, string $shifts): LogSheet
{
    test()->post(p('logbooks/'.test()->logbook->id.'/sheets'), ['name' => "{$code} log", 'code' => $code, 'review_required' => false])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', $code)->firstOrFail());
    test()->post(p("sheets/{$sheet->id}/publish"), ['fields' => generatorFields(), 'recording' => ['mode' => 'on_demand', 'shifts' => $shifts]])->assertSessionHasNoErrors();

    return inTenant(fn () => $sheet->fresh());
}

function addShift(string $name, string $starts, string $ends, array $extra = []): Shift
{
    test()->post(p('shifts'), ['name' => $name, 'starts_time' => $starts, 'ends_time' => $ends, ...$extra])->assertSessionHasNoErrors();

    return inTenant(fn () => Shift::query()->where('name', $name)->firstOrFail());
}

function shiftEntry(LogSheet $sheet, array $extra = [])
{
    return test()->post(p('entries'), [
        'sheet_id' => $sheet->id, 'occurred_at' => '2026-11-20T23:15', 'values' => generatorValues(),
        'idempotency_key' => (string) Str::uuid7(), 'submit' => true, ...$extra,
    ]);
}

it('keeps shifts, including one past midnight, and refuses overlaps in the same logbook', function () {
    $morning = addShift('Morning', '06:00', '14:00');
    $night = addShift('Night', '22:00', '06:00', ['member_ids' => [$this->team['writer']->id]]);
    expect($night->overnight())->toBeTrue()->and(inTenant(fn () => $night->members->pluck('id')->all()))->toBe([$this->team['writer']->id]);

    // 05:00–07:00 runs into both; 04:00–05:00 into the night shift's morning end.
    $this->post(p('shifts'), ['name' => 'Early', 'starts_time' => '05:00', 'ends_time' => '07:00'])->assertSessionHasErrors('starts_time');
    $this->post(p('shifts'), ['name' => 'Dawn', 'starts_time' => '04:00', 'ends_time' => '05:00'])->assertSessionHasErrors('starts_time');
    $this->post(p('shifts'), ['name' => 'Same', 'starts_time' => '08:00', 'ends_time' => '08:00'])->assertSessionHasErrors('ends_time');
    $this->post(p('shifts'), ['name' => 'Bad', 'starts_time' => '8am', 'ends_time' => '09:00'])->assertSessionHasErrors('starts_time');
    // Afternoon fits exactly between them.
    addShift('Afternoon', '14:00', '22:00');

    // Another logbook's own shift does not meet this logbook's, but a workspace-wide one meets everything.
    $other = logbook('Fleet');
    $this->post(p('shifts'), ['name' => 'Fleet day', 'starts_time' => '07:00', 'ends_time' => '15:00', 'logbook_id' => $other->id])->assertSessionHasErrors('starts_time');
    inTenant(fn () => Shift::query()->whereKey($morning->id)->update(['logbook_id' => $this->logbook->id]));
    $this->post(p('shifts'), ['name' => 'Fleet day', 'starts_time' => '07:00', 'ends_time' => '13:00', 'logbook_id' => $other->id])->assertSessionHasNoErrors();

    // Switched off, a shift no longer blocks; past entries keep it.
    $this->put(p("shifts/{$night->id}"), ['name' => 'Night', 'starts_time' => '22:00', 'ends_time' => '06:00', 'active' => false])->assertSessionHasNoErrors();
    $this->post(p('shifts'), ['name' => 'Late', 'starts_time' => '22:00', 'ends_time' => '02:00'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => AuditEvent::query()->where('action', 'shift.updated')->count()))->toBe(1);
});

it('lets only managers keep shifts, while everyone may see them', function () {
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('shifts'))->assertOk();
    $this->post(p('shifts'), ['name' => 'Morning', 'starts_time' => '06:00', 'ends_time' => '14:00'])->assertForbidden();
});

it('records the shift on the entry and each revision, and refuses shifts of other logbooks', function () {
    $night = addShift('Night', '22:00', '06:00');
    $fleet = addShift('Fleet only', '08:00', '10:00', ['logbook_id' => logbook('Fleet')->id]);
    $sheet = shiftSheet('GEN', 'optional');

    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p("entries/new?sheet={$sheet->id}"))->assertInertia(fn ($page) => $page
        ->where('shiftSetting', 'optional')
        ->where('shifts', fn ($s) => collect($s)->pluck('value')->all() === [$night->id]));

    shiftEntry($sheet, ['shift_id' => $fleet->id])->assertSessionHasErrors('shift_id');
    shiftEntry($sheet, ['shift_id' => $night->id])->assertSessionHasNoErrors();
    $entry = latestEntry();
    expect($entry->shift_id)->toBe($night->id)
        ->and(inTenant(fn () => EntryRevision::query()->where('log_entry_id', $entry->id)->value('shift_id')))->toBe($night->id);
    $this->get(p("entries/{$entry->id}"))->assertInertia(fn ($page) => $page->where('entry.shift', 'Night · 22:00–06:00'));

    // Optional: none is fine.
    shiftEntry($sheet)->assertSessionHasNoErrors();
    expect(latestEntry()->shift_id)->toBeNull();
});

it('requires a shift where the template says always, and ignores one where it says no', function () {
    $night = addShift('Night', '22:00', '06:00');
    $always = shiftSheet('GEN', 'required');
    $never = shiftSheet('OBS', 'off');

    as_($this->team['writer'], 'logbook_contributor');
    shiftEntry($always)->assertSessionHasErrors('shift_id');
    // A draft may wait for it; submitting may not.
    shiftEntry($always, ['submit' => false])->assertSessionHasNoErrors();
    $draft = latestEntry();
    $this->put(p("entries/{$draft->id}"), ['occurred_at' => '2026-11-20T23:15', 'values' => generatorValues(), 'submit' => true])->assertSessionHasErrors('shift_id');
    $this->put(p("entries/{$draft->id}"), ['occurred_at' => '2026-11-20T23:15', 'values' => generatorValues(), 'shift_id' => $night->id, 'submit' => true])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $draft->fresh()->shift_id))->toBe($night->id);

    shiftEntry($never, ['shift_id' => $night->id])->assertSessionHasNoErrors();
    expect(latestEntry()->shift_id)->toBeNull();
    $this->get(p("entries/new?sheet={$never->id}"))->assertInertia(fn ($page) => $page->where('shiftSetting', 'off')->where('shifts', []));
});

it('keeps the shift an entry was given when the shift is renamed or switched off', function () {
    $night = addShift('Night', '22:00', '06:00');
    $sheet = shiftSheet('GEN', 'optional');
    as_($this->team['writer'], 'logbook_contributor');
    shiftEntry($sheet, ['shift_id' => $night->id])->assertSessionHasNoErrors();
    $entry = latestEntry();

    as_($this->team['manager'], 'logbook_manager');
    $this->put(p("shifts/{$night->id}"), ['name' => 'Night watch', 'starts_time' => '22:00', 'ends_time' => '06:00', 'active' => false])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $entry->fresh()->shift_id))->toBe($night->id);

    // Switched off: no longer offered or accepted on new entries.
    as_($this->team['writer'], 'logbook_contributor');
    shiftEntry($sheet, ['shift_id' => $night->id])->assertSessionHasErrors('shift_id');
});

it('filters reports by shift', function () {
    $morning = addShift('Morning', '06:00', '14:00');
    $night = addShift('Night', '22:00', '06:00');
    $sheet = shiftSheet('GEN', 'optional');
    as_($this->team['writer'], 'logbook_contributor');
    shiftEntry($sheet, ['shift_id' => $night->id]);
    shiftEntry($sheet, ['shift_id' => $morning->id, 'occurred_at' => '2026-11-20T08:00']);
    shiftEntry($sheet);

    as_($this->team['manager'], 'logbook_manager');
    $this->get(p("reports?view=table&shift={$night->id}"))->assertInertia(fn ($page) => $page
        ->where('counts.total', 1)
        ->where('options.shifts', fn ($s) => count($s) === 2));
});

it('takes the shift from phones and gives phones the shifts', function () {
    $night = addShift('Night', '22:00', '06:00', ['member_ids' => [$this->team['writer']->id]]);
    $sheet = shiftSheet('GEN', 'required');
    $url = fn (string $path) => $this->workspaceUrl('acme', 'api/v1/'.$path);

    $this->actingAsAppMember('acme', rolePermissions('logbook_contributor'), user: $this->team['writer']);
    Cache::flush();
    $kit = $this->getJson($url('kit'))->assertOk();
    expect($kit->json('shifts.0.id'))->toBe($night->id)->and($kit->json('shifts.0.members'))->toBe([$this->team['writer']->id]);
    expect(collect($kit->json('sheets'))->firstWhere('id', $sheet->id)['recording'])->toBe(['mode' => 'on_demand', 'shifts' => 'required', 'activity' => null, 'stops' => 'off']);

    $this->postJson($url('entries'), [
        'id' => (string) Str::uuid7(), 'sheet_id' => $sheet->id, 'version_id' => $sheet->published_version_id,
        'occurred_at' => '2026-11-20T23:15:00+03:00', 'values' => generatorValues(), 'submit' => true, 'shift_id' => $night->id,
    ])->assertSuccessful();
    expect(latestEntry()->shift_id)->toBe($night->id);
});
