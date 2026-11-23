<?php

use App\Logbook\AssetTemplates;
use App\Logbook\Models\AssetTemplate;
use App\Logbook\Models\Entry;
use App\Logbook\Models\FuelRate;
use App\Logbook\Models\Location;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\Schedule;
use App\Logbook\Models\ScheduleOccurrence;
use App\Logbook\Models\Task;
use App\Logbook\Recording;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 * ADR-0031: assets registered from templates, linked to the log sheets
 * that record them, scheduled one by one, named on every entry, and
 * reported by asset. Managers keep the asset register (logbook.manage).
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 00:05:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook('Stores');
    $this->post(p('locations'), ['name' => 'Main warehouse'])->assertSessionHasNoErrors();
    $this->place = inTenant(fn () => Location::query()->where('name', 'Main warehouse')->sole());
});

function registerAsset(string $code, string $template = 'starter:cold_storage', array $extra = []): LogResource
{
    $starter = str_starts_with($template, 'starter:') ? AssetTemplates::starters()[substr($template, 8)] : null;
    test()->post(p('resources'), [
        'template' => $template, 'code' => $code, 'name' => "{$code} room", 'type' => $starter['type'] ?? 'Cold storage room',
        'kind' => $starter['kind'] ?? 'asset', 'location_id' => test()->place->id, ...$extra,
    ])->assertSessionHasNoErrors();

    return inTenant(fn () => LogResource::query()->where('code', strtoupper($code))->sole());
}

/** A temperature log: the room or unit, and a temperature with a normal range of 2–8 °C. */
function temperatureSheet(string $code = 'TMP', ?string $type = null): LogSheet
{
    return publishedSheet(test()->logbook, $code, review: false, fields: [
        ['name' => 'unit', 'label' => 'Room or unit', 'type' => 'resource', 'required' => true, ...($type ? ['resource_type' => $type] : [])],
        ['name' => 'temperature', 'label' => 'Temperature', 'type' => 'decimal', 'required' => true, 'unit' => '°C', 'expected_min' => '2', 'expected_max' => '8', 'raise_exception' => true, 'exception_severity' => 'high'],
        ['name' => 'litres', 'label' => 'Water used', 'type' => 'decimal', 'required' => false, 'unit' => 'L'],
    ]);
}

function reading(LogSheet $sheet, string $assetId, string $temperature, string $at = '2026-11-20T06:10', array $extra = [])
{
    return test()->post(p('entries'), [
        'sheet_id' => $sheet->id, 'occurred_at' => $at, 'values' => ['unit' => $assetId, 'temperature' => $temperature],
        'idempotency_key' => (string) Str::uuid7(), 'submit' => true, ...$extra,
    ]);
}

it('registers an asset from a starter template, keeping a snapshot and its own details', function () {
    $this->get(p('resources/create'))->assertOk()->assertInertia(fn ($page) => $page->component('resources/form')->has('templates', 7));

    $this->post(p('resources'), [
        'template' => 'starter:generator', 'code' => 'gen-01', 'name' => 'Main generator', 'type' => 'Generator', 'kind' => 'asset',
        'location_id' => $this->place->id, 'manufacturer' => 'Perkins', 'model' => '1104A', 'serial_number' => 'SN-778',
        'responsible_id' => $this->team['writer']->id,
        'metadata' => [['label' => 'Rated power', 'value' => '60', 'unit' => 'kVA'], ['label' => 'Fuel tank capacity', 'value' => ''], ['label' => 'Supplier', 'value' => 'Acme Power']],
        'fuel' => ['method' => 'hours', 'fuel_type' => 'diesel', 'rate' => '4.5', 'effective_from' => '2026-11-01T00:00'],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $generator = inTenant(fn () => LogResource::query()->where('code', 'GEN-01')->sole());
    expect($generator->template['name'])->toBe('Generator')->and($generator->template['version'])->toBe(1)
        ->and($generator->template['suggested_sheets'])->toContain('generator_operation')
        ->and($generator->metadata)->toBe([
            'rated_power' => ['label' => 'Rated power', 'value' => '60', 'unit' => 'kVA'],
            'supplier' => ['label' => 'Supplier', 'value' => 'Acme Power'],
        ])
        ->and([$generator->manufacturer, $generator->model, $generator->serial_number, $generator->responsible_id])->toBe(['Perkins', '1104A', 'SN-778', $this->team['writer']->id])
        ->and($generator->status)->toBe('active')->and($generator->active)->toBeTrue();
    expect(inTenant(fn () => FuelRate::query()->where('log_resource_id', $generator->id)->sole()->rate))->toEqual('4.500');

    $this->get(p("resources/{$generator->id}"))->assertOk()->assertInertia(fn ($page) => $page
        ->where('resource.template.name', 'Generator')
        ->has('suggested', 4)->where('suggested.0.key', 'generator_operation'));
});

it('asks where a monitored location is, and keeps it apart from physical assets', function () {
    $this->post(p('resources'), ['template' => 'starter:cold_storage', 'code' => 'STORE-1', 'name' => 'Cold room', 'type' => 'Cold storage room', 'kind' => 'monitored_location'])
        ->assertSessionHasErrors('location_id');
    $room = registerAsset('STORE-1');
    $unit = registerAsset('AC-1', 'starter:air_conditioner');
    expect([$room->kind, $unit->kind])->toBe(['monitored_location', 'asset']);
});

it('lets only managers register, change, link or retire assets; everyone in Logbook reads them', function () {
    $room = registerAsset('STORE-1');
    $sheet = temperatureSheet();
    foreach (['writer' => 'logbook_contributor', 'viewer' => 'logbook_viewer'] as $who => $role) {
        as_($this->team[$who], $role);
        $this->get(p("resources/{$room->id}"))->assertOk();
        $this->get(p('resources/create'))->assertForbidden();
        $this->post(p('resources'), ['code' => 'X-1', 'name' => 'X', 'type' => 'Equipment'])->assertForbidden();
        $this->put(p("resources/{$room->id}"), ['code' => 'STORE-1', 'name' => 'Renamed', 'type' => 'Cold storage room', 'status' => 'retired'])->assertForbidden();
        $this->post(p("resources/{$room->id}/sheets"), ['sheet_id' => $sheet->id])->assertForbidden();
        $this->post(p('resources/templates'), ['name' => 'Mine', 'type' => 'Thing'])->assertForbidden();
    }
    expect(inTenant(fn () => $room->fresh()->name))->toBe('STORE-1 room');
});

it('versions an organization template without changing assets registered from it', function () {
    $this->post(p('resources/templates'), [
        'name' => 'Vaccine fridge', 'type' => 'Refrigerator', 'kind' => 'asset', 'fields' => [['label' => 'Volume', 'type' => 'number', 'unit' => 'L']],
        'measurements' => ['Temperature'], 'suggested_sheets' => ['temperature_monitoring', 'not_a_starter'],
    ])->assertSessionHasNoErrors();
    $template = inTenant(fn () => AssetTemplate::query()->sole());
    expect($template->suggested_sheets)->toBe(['temperature_monitoring'])->and($template->version)->toBe(1);

    $fridge = registerAsset('FRG-1', $template->id, ['type' => 'Refrigerator', 'kind' => 'asset']);
    $this->put(p("resources/templates/{$template->id}"), ['name' => 'Vaccine fridge', 'type' => 'Refrigerator', 'fields' => [['label' => 'Volume', 'type' => 'number', 'unit' => 'L'], ['label' => 'Alarm fitted']]])
        ->assertSessionHasNoErrors();

    expect(inTenant(fn () => $template->fresh()->version))->toBe(2)
        ->and(inTenant(fn () => $fridge->fresh()->template))->toMatchArray(['key' => $template->id, 'version' => 1])
        ->and(inTenant(fn () => count($fridge->fresh()->template['fields'])))->toBe(1);
    // A starter name stays the starter's.
    $this->post(p('resources/templates'), ['name' => 'Generator', 'type' => 'Generator'])->assertSessionHasErrors('name');
});

it('links one sheet to several assets and one asset to several sheets, refusing what does not fit', function () {
    $one = registerAsset('STORE-1');
    $two = registerAsset('STORE-2');
    $pump = registerAsset('PMP-1', 'starter:water_pump');
    $temperature = temperatureSheet('TMP', 'Cold storage room');
    $inspection = publishedSheet($this->logbook, 'INS', review: false, fields: [
        ['name' => 'equipment', 'label' => 'Equipment', 'type' => 'resource', 'required' => true],
        ['name' => 'ok', 'label' => 'All good', 'type' => 'yes_no', 'required' => true],
    ]);
    $plain = publishedSheet($this->logbook, 'GEN');

    $this->post(p("sheets/{$temperature->id}/resources"), ['resource_ids' => [$one->id, $two->id]])->assertSessionHasNoErrors();
    $this->post(p("resources/{$one->id}/sheets"), ['sheet_id' => $inspection->id])->assertSessionHasNoErrors();
    $this->post(p("resources/{$pump->id}/sheets"), ['sheet_id' => $temperature->id])->assertSessionHasErrors('link');
    $this->post(p("resources/{$pump->id}/sheets"), ['sheet_id' => $plain->id])->assertSessionHasErrors('link');

    inTenant(function () use ($one, $temperature) {
        expect($one->sheets()->pluck('code')->sort()->values()->all())->toBe(['INS', 'TMP'])
            ->and($temperature->resources()->pluck('code')->sort()->values()->all())->toBe(['STORE-1', 'STORE-2']);
    });

    // Retired: no new links; its page and history stay.
    $this->put(p("resources/{$pump->id}"), ['code' => 'PMP-1', 'name' => 'Pump', 'type' => 'Water pump', 'status' => 'retired'])->assertSessionHasNoErrors();
    $this->post(p("resources/{$pump->id}/sheets"), ['sheet_id' => $inspection->id])->assertSessionHasErrors('link');
    expect(inTenant(fn () => $pump->fresh()->active))->toBeFalse();
    $this->get(p("resources/{$pump->id}"))->assertOk()->assertInertia(fn ($page) => $page->where('resource.status', 'retired'));
});

it('names the asset on each entry, and offers only the linked assets once a sheet has links', function () {
    $one = registerAsset('STORE-1');
    $two = registerAsset('STORE-2');
    $sheet = temperatureSheet();
    as_($this->team['writer'], 'logbook_contributor');

    // No links yet: any active asset, as before.
    reading($sheet, $two->id, '4')->assertSessionHasNoErrors();
    expect(latestEntry()->resource_id)->toBe($two->id);

    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("sheets/{$sheet->id}/resources"), ['resource_ids' => [$one->id]])->assertSessionHasNoErrors();

    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p("entries/new?sheet={$sheet->id}"))->assertOk()->assertInertia(fn ($page) => $page
        ->where('fields.0.subject', true)->where('fields.0.resource_ids', [$one->id]));
    reading($sheet, $two->id, '4')->assertSessionHasErrors('values.unit');
    reading($sheet, $one->id, '4')->assertSessionHasNoErrors();
    expect(latestEntry()->resource_id)->toBe($one->id);

    // Retired while a draft names it: refused, never moved to another asset.
    $this->post(p('entries'), ['sheet_id' => $sheet->id, 'occurred_at' => '2026-11-20T06:20', 'values' => ['unit' => $one->id, 'temperature' => '5'],
        'idempotency_key' => (string) Str::uuid7(), 'submit' => false])->assertSessionHasNoErrors();
    $draft = latestEntry();
    as_($this->team['manager'], 'logbook_manager');
    $this->put(p("resources/{$one->id}"), ['code' => 'STORE-1', 'name' => 'Cold room', 'type' => 'Cold storage room', 'kind' => 'monitored_location', 'location_id' => $this->place->id, 'status' => 'retired'])
        ->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    $this->post(p("entries/{$draft->id}/submit"))->assertSessionHasErrors('values.unit');
    expect(inTenant(fn () => $draft->fresh()->status))->toBe('draft')->and(inTenant(fn () => $draft->fresh()->resource_id))->toBe($one->id);
});

it('keeps each asset\'s expected readings apart on a shared sheet', function () {
    $one = registerAsset('STORE-1');
    $two = registerAsset('STORE-2');
    $three = registerAsset('STORE-3');
    $sheet = temperatureSheet();
    $this->post(p("sheets/{$sheet->id}/resources"), ['resource_ids' => [$one->id, $two->id, $three->id]])->assertSessionHasNoErrors();
    $schedule = fn (string $resource, array $times) => $this->post(p("sheets/{$sheet->id}/schedules"), [
        'frequency' => 'daily', 'late_minutes' => 60, 'timezone' => 'UTC', 'starts_on' => '2026-11-20', 'completion' => 'submitted',
        'times' => $times, 'resource_id' => $resource,
    ]);
    $schedule($one->id, [['opens' => '05:30', 'due' => '06:30'], ['opens' => '13:30', 'due' => '14:30'], ['opens' => '21:30', 'due' => '22:30']])->assertSessionHasNoErrors();
    $schedule($two->id, [['opens' => '07:30', 'due' => '08:30'], ['opens' => '19:30', 'due' => '20:30']])->assertSessionHasNoErrors();
    // STORE-3 stays on demand. An unlinked asset gets no schedule here.
    $pump = registerAsset('PMP-1', 'starter:water_pump');
    $schedule($pump->id, [['opens' => '05:30', 'due' => '06:30']])->assertSessionHasErrors('resource_id');

    $this->artisan('logbook:operations')->assertSuccessful();
    $today = inTenant(fn () => ScheduleOccurrence::query()->whereDate('occurs_on', '2026-11-20')->get());
    expect($today->where('resource_id', $one->id))->toHaveCount(3)->and($today->where('resource_id', $two->id))->toHaveCount(2)
        ->and($today->where('resource_id', $three->id))->toHaveCount(0);

    // STORE-2's reading at 06:10 falls in STORE-1's window but never meets it.
    as_($this->team['writer'], 'logbook_contributor');
    $this->travelTo('2026-11-20 06:15:00');
    reading($sheet, $two->id, '5', '2026-11-20T06:10')->assertSessionHasNoErrors();
    $morningOne = inTenant(fn () => ScheduleOccurrence::query()->where('resource_id', $one->id)->orderBy('due_at')->first());
    expect($morningOne->log_entry_id)->toBeNull();

    reading($sheet, $one->id, '5', '2026-11-20T06:12')->assertSessionHasNoErrors();
    expect(inTenant(fn () => $morningOne->fresh()))->status->toBe('completed')->log_entry_id->toBe(latestEntry()->id);

    // A reading named for STORE-2's occurrence but about STORE-1 does not complete it.
    $this->travelTo('2026-11-20 08:00:00');
    $twoMorning = inTenant(fn () => ScheduleOccurrence::query()->where('resource_id', $two->id)->orderBy('due_at')->first());
    reading($sheet, $one->id, '5', '2026-11-20T07:55', ['occurrence_id' => $twoMorning->id, 'schedule_link' => 'named'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $twoMorning->fresh()->log_entry_id))->toBeNull();

    // A schedule keeps its asset.
    as_($this->team['manager'], 'logbook_manager');
    $s = inTenant(fn () => Schedule::query()->where('resource_id', $one->id)->sole());
    $this->put(p("schedules/{$s->id}"), ['frequency' => 'daily', 'late_minutes' => 60, 'timezone' => 'UTC', 'starts_on' => '2026-11-20', 'completion' => 'submitted',
        'times' => [['opens' => '05:30', 'due' => '06:30']], 'resource_id' => $two->id])->assertSessionHasErrors('resource_id');
    // Unlinking waits for its schedule to end.
    $this->delete(p("sheets/{$sheet->id}/resources/{$one->id}"))->assertSessionHasErrors('link');
});

it('stores and flags a reading out of range, raises the exception on the asset, and a corrective task follows it', function () {
    $room = registerAsset('STORE-1');
    $sheet = temperatureSheet();
    as_($this->team['writer'], 'logbook_contributor');
    reading($sheet, $room->id, '11.5')->assertSessionHasNoErrors();
    $entry = latestEntry();
    expect($entry->values['temperature'])->toEqual('11.5')->and($entry->status)->toBe('finalized');

    $exception = inTenant(fn () => LogException::query()->where('log_entry_id', $entry->id)->sole());
    expect($exception->resource_id)->toBe($room->id);

    as_($this->team['manager'], 'logbook_manager');
    $this->post(p('tasks'), ['title' => 'Check the cooling unit', 'assignee_id' => $this->team['writer']->id, 'exception_id' => $exception->id, 'priority' => 'high'])
        ->assertSessionHasNoErrors();
    expect(inTenant(fn () => Task::query()->latest('created_at')->first()->resource_id))->toBe($room->id);

    $this->get(p("resources/{$room->id}"))->assertInertia(fn ($page) => $page
        ->where('recordCount', 1)->where('records.0.outOfRange', 1)->where('exceptions.0.id', $exception->id)->has('tasks', 1));
});

it('reports by asset, measures per asset without adding up temperatures, and counts compliance', function () {
    $one = registerAsset('STORE-1');
    $two = registerAsset('STORE-2');
    $sheet = temperatureSheet();
    $this->post(p("sheets/{$sheet->id}/schedules"), ['frequency' => 'daily', 'late_minutes' => 30, 'timezone' => 'UTC', 'starts_on' => '2026-11-20', 'completion' => 'submitted',
        'times' => [['opens' => '05:30', 'due' => '06:30'], ['opens' => '13:30', 'due' => '14:30']], 'resource_id' => $one->id])->assertSessionHasNoErrors();
    $this->artisan('logbook:operations')->assertSuccessful();

    as_($this->team['writer'], 'logbook_contributor');
    $this->travelTo('2026-11-20 06:20:00');
    reading($sheet, $one->id, '4', '2026-11-20T06:15')->assertSessionHasNoErrors();
    reading($sheet, $one->id, '10', '2026-11-20T06:18', ['schedule_link' => 'extra'])->assertSessionHasNoErrors();
    reading($sheet, $two->id, '6', '2026-11-20T06:19')->assertSessionHasNoErrors();
    $this->travelTo('2026-11-20 16:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();

    as_($this->team['manager'], 'logbook_manager');
    $this->get(p("reports?view=assets&sheet={$sheet->id}&from=2026-11-20&to=2026-11-20"))->assertOk()->assertInertia(fn ($page) => $page
        ->where('assets.rows.0.label', 'STORE-1 · STORE-1 room')->where('assets.rows.0.records', 2)->where('assets.rows.0.outOfRange', 1)
        ->where('assets.rows.0.expected', 2)->where('assets.rows.0.onTime', 1)->where('assets.rows.0.missed', 1)
        ->where('assets.rows.1.records', 1)
        ->where('measurements.0.label', 'STORE-1 · STORE-1 room')
        ->where('measurements.0.fields.0.average', 7)->where('measurements.0.fields.0.min', 4)->where('measurements.0.fields.0.max', 10)
        // No total of temperatures; litres would add up.
        ->where('measurements.0.fields.0.sum', null)->where('measurements.0.fields.0.outOfRange', 1));
    $this->get(p("reports?view=compliance&from=2026-11-20&to=2026-11-20&resource={$one->id}"))->assertInertia(fn ($page) => $page
        ->where('compliance.totals.expected', 2)->where('compliance.totals.onTime', 1)->where('compliance.totals.missed', 1)
        ->where('compliance.totals.rate', 50)->where('compliance.rows.0.resource', 'STORE-1 · STORE-1 room'));
    $this->get(p("reports?view=table&resource={$two->id}"))->assertInertia(fn ($page) => $page->where('counts.total', 1));
    $this->get(p('reports?view=table&category=Cold storage room'))->assertInertia(fn ($page) => $page->where('counts.total', 3));

    // Exports say what they hold.
    $csv = $this->get(p("reports/compliance.csv?from=2026-11-20&to=2026-11-20&resource={$one->id}"))->assertOk()->streamedContent();
    expect($csv)->toContain('Scheduled compliance · Occurred 2026-11-20 to 2026-11-20 · Asset: STORE-1 · STORE-1 room')
        ->and($csv)->toContain('Submitted on time');
    $this->get(p('reports/assets.pdf?from=2026-11-20'))->assertOk()->assertHeader('content-type', 'application/pdf');

    // Contributors have no reports.
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('reports/assets.csv'))->assertForbidden();
});

it('gives the phone the main field and the linked assets, and refuses an unlinked one', function () {
    $one = registerAsset('STORE-1');
    $two = registerAsset('STORE-2');
    $sheet = temperatureSheet();
    $this->post(p("sheets/{$sheet->id}/resources"), ['resource_ids' => [$one->id]])->assertSessionHasNoErrors();
    $this->actingAsAppMember('acme', rolePermissions('logbook_contributor'), user: $this->team['writer']);
    Cache::flush();
    $url = fn (string $path) => $this->workspaceUrl('acme', 'api/v1/'.$path);

    $kit = $this->getJson($url('kit'))->assertOk();
    $kitSheet = collect($kit->json('sheets'))->firstWhere('id', $sheet->id);
    expect($kitSheet['subject'])->toBe('unit')->and($kitSheet['assets'])->toBe([$one->id])
        ->and(collect($kit->json('resources'))->firstWhere('id', $one->id)['kind'])->toBe('monitored_location');
    $this->getJson($url('sheet-versions/'.$sheet->published_version_id))->assertJsonPath('subject', 'unit');

    $post = fn (string $asset) => $this->postJson($url('entries'), [
        'id' => (string) Str::uuid7(), 'sheet_id' => $sheet->id, 'version_id' => $sheet->published_version_id, 'submit' => true,
        'occurred_at' => '2026-11-20T06:00:00Z', 'values' => ['unit' => $asset, 'temperature' => '5'],
    ]);
    $post($two->id)->assertUnprocessable()->assertJsonValidationErrors('values.unit');
    $post($one->id)->assertSuccessful()->assertJsonPath('resourceId', $one->id);
});

it('never reaches another workspace\'s assets', function () {
    $room = registerAsset('STORE-1');
    $sheet = temperatureSheet();
    $other = team('globex');
    as_($other['manager'], 'logbook_manager', 'globex');
    $theirs = logbook('Their stores', slug: 'globex');
    $this->get(p("resources/{$room->id}", 'globex'))->assertNotFound();
    $this->post(p("resources/{$room->id}/sheets", 'globex'), ['sheet_id' => $sheet->id])->assertNotFound();
    $their = publishedSheet($theirs, 'TMP', review: false, fields: [['name' => 'unit', 'label' => 'Unit', 'type' => 'resource', 'required' => true]], slug: 'globex');
    $this->post(p("sheets/{$their->id}/resources", 'globex'), ['resource_ids' => [$room->id]])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $their->resources()->count(), 'globex'))->toBe(0);
});

it('takes the main asset from the builder, the fuel setting, or the only resource field', function () {
    $sheet = publishedSheet($this->logbook, 'TWO', review: false, fields: [
        ['name' => 'vehicle', 'label' => 'Vehicle', 'type' => 'resource', 'required' => true],
        ['name' => 'trailer', 'label' => 'Trailer', 'type' => 'resource', 'required' => false],
    ]);
    expect(inTenant(fn () => Recording::subjectField($sheet->publishedVersion)))->toBeNull();

    $this->put(p("sheets/{$sheet->id}/draft"), ['fields' => inTenant(fn () => $sheet->publishedVersion->fields), 'recording' => ['mode' => 'on_demand', 'subject' => 'trailer']])->assertSessionHasNoErrors();
    $this->post(p("sheets/{$sheet->id}/publish"))->assertSessionHasNoErrors();
    expect(inTenant(fn () => Recording::subjectField($sheet->fresh()->publishedVersion)))->toBe('trailer');

    $this->put(p("sheets/{$sheet->id}/draft"), ['fields' => inTenant(fn () => $sheet->fresh()->publishedVersion->fields), 'recording' => ['mode' => 'on_demand', 'subject' => 'nope']])
        ->assertSessionHasErrors('recording.subject');
    expect(inTenant(fn () => Recording::subjectField(publishedSheet($this->logbook, 'ONE')->publishedVersion)))->toBeNull();
    expect(inTenant(fn () => Entry::query()->count()))->toBe(0);
});
