<?php

use App\Logbook\Fuel;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\FuelRate;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\MeterEvent;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/*
 * ADR-0030 phase 4: fuel rates per resource, with history, and meter
 * events. Kept by managers, audited, never edited.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 10:00:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p('resources'), ['code' => 'VEH-01', 'name' => 'Land Cruiser', 'type' => 'Vehicle'])->assertSessionHasNoErrors();
    $this->post(p('resources'), ['code' => 'ROOM-1', 'name' => 'Store room', 'type' => 'Room'])->assertSessionHasNoErrors();
    $this->vehicle = inTenant(fn () => LogResource::query()->where('code', 'VEH-01')->sole());
    $this->room = inTenant(fn () => LogResource::query()->where('code', 'ROOM-1')->sole());
});

function addRate(LogResource $resource, string $rate, string $from, array $extra = [])
{
    return test()->post(p("resources/{$resource->id}/fuel-rates"), [
        'fuel_type' => 'diesel', 'method' => 'distance', 'rate' => $rate, 'effective_from' => $from, ...$extra,
    ]);
}

it('uses the rate in force at the moment, and keeps every earlier rate', function () {
    addRate($this->vehicle, '12.5', '2026-01-01T00:00')->assertSessionHasNoErrors();
    addRate($this->vehicle, '14', '2026-06-01T00:00', ['note' => 'Engine rebuilt'])->assertSessionHasNoErrors();
    // A later rate set in advance: not in force yet.
    addRate($this->vehicle, '13', '2026-12-01T00:00')->assertSessionHasNoErrors();

    $at = fn (string $t) => inTenant(fn () => Fuel::rateAt($this->vehicle->id, CarbonImmutable::parse($t))?->rate);
    expect($at('2025-12-31 23:59'))->toBeNull()
        ->and($at('2026-03-01 08:00'))->toBe('12.500')
        ->and($at('2026-06-01 00:00'))->toBe('14.000')
        ->and($at('2026-11-20 10:00'))->toBe('14.000')
        ->and($at('2026-12-02 00:00'))->toBe('13.000');

    // A correction with the same start supersedes it, and the first stays on record.
    addRate($this->vehicle, '14.2', '2026-06-01T00:00')->assertSessionHasNoErrors();
    expect($at('2026-07-01 00:00'))->toBe('14.200')
        ->and(inTenant(fn () => FuelRate::query()->where('log_resource_id', $this->vehicle->id)->count()))->toBe(4);

    $this->get(p("resources/{$this->vehicle->id}"))->assertInertia(fn ($page) => $page
        ->component('resources/show')
        ->has('rates', 4)
        ->where('rates.0.upcoming', true)
        ->where('currentRate', inTenant(fn () => Fuel::rateAt($this->vehicle->id, CarbonImmutable::now())->id)));
    $this->get(p('resources'))->assertInertia(fn ($page) => $page
        ->where('resources', fn ($rows) => collect($rows)->firstWhere('code', 'VEH-01')['fuelRate'] === '14.2 L/100 km'
            && collect($rows)->firstWhere('code', 'ROOM-1')['fuelRate'] === null));
    expect(inTenant(fn () => AuditEvent::query()->where('action', 'resource.fuel_rate_added')->count()))->toBe(4);
});

it('reads local times in the workspace time zone', function () {
    addRate($this->vehicle, '10', '2026-11-20T08:00')->assertSessionHasNoErrors();
    $tz = inTenant(fn () => Workspace::current()->timezone ?? 'UTC');
    $stored = inTenant(fn () => FuelRate::query()->sole()->effective_from);
    expect($stored->utc()->toIso8601String())->toBe(CarbonImmutable::parse('2026-11-20 08:00', $tz)->utc()->toIso8601String());
});

it('gives no estimate for a resource without a rate', function () {
    expect(inTenant(fn () => Fuel::rateAt($this->room->id, CarbonImmutable::now())))->toBeNull();
    $this->get(p("resources/{$this->room->id}"))->assertInertia(fn ($page) => $page->where('currentRate', null)->has('rates', 0));
});

it('refuses rates that make no sense', function () {
    addRate($this->vehicle, '0', '2026-01-01T00:00')->assertSessionHasErrors('rate');
    addRate($this->vehicle, '-3', '2026-01-01T00:00')->assertSessionHasErrors('rate');
    addRate($this->vehicle, 'lots', '2026-01-01T00:00')->assertSessionHasErrors('rate');
    addRate($this->vehicle, '12', '2026-01-01T00:00', ['method' => 'weight'])->assertSessionHasErrors('method');
    addRate($this->vehicle, '12', '2026-01-01T00:00', ['fuel_type' => 'coal'])->assertSessionHasErrors('fuel_type');
    addRate($this->vehicle, '12', 'someday')->assertSessionHasErrors('effective_from');
    expect(inTenant(fn () => FuelRate::query()->count()))->toBe(0);
});

it('records meter events with a reason, never in the future', function () {
    $post = fn (array $data) => $this->post(p("resources/{$this->vehicle->id}/meter-events"), [
        'measure' => 'odometer', 'kind' => 'replaced', 'reading' => '0', 'occurred_at' => '2026-11-19T09:00', 'reason' => 'Cluster replaced at the garage', ...$data,
    ]);
    $post(['reason' => ''])->assertSessionHasErrors('reason');
    $post(['reading' => '-1'])->assertSessionHasErrors('reading');
    $post(['measure' => 'fuel_gauge'])->assertSessionHasErrors('measure');
    $post(['kind' => 'lost'])->assertSessionHasErrors('kind');
    $post(['occurred_at' => '2026-11-21T09:00'])->assertSessionHasErrors('occurred_at');
    $post([])->assertSessionHasNoErrors();
    $post(['measure' => 'hour_meter', 'kind' => 'reset', 'reading' => '12.5', 'occurred_at' => '2026-11-20T09:00'])->assertSessionHasNoErrors();

    $before = inTenant(fn () => Fuel::meterEventBefore($this->vehicle->id, 'odometer', CarbonImmutable::parse('2026-11-19 08:00', 'UTC')));
    $after = inTenant(fn () => Fuel::meterEventBefore($this->vehicle->id, 'odometer', CarbonImmutable::now()));
    expect($before)->toBeNull()->and($after?->kind)->toBe('replaced')->and($after?->reading)->toBe('0.0');
    expect(inTenant(fn () => MeterEvent::query()->count()))->toBe(2)
        ->and(inTenant(fn () => AuditEvent::query()->where('action', 'resource.meter_event')->count()))->toBe(2);
    $this->get(p("resources/{$this->vehicle->id}"))->assertInertia(fn ($page) => $page->has('meterEvents', 2)->where('meterEvents.0.measure', 'hour_meter'));
});

it('lets everyone read fuel settings and only managers add to them', function () {
    addRate($this->vehicle, '12', '2026-01-01T00:00')->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p("resources/{$this->vehicle->id}"))->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false)->has('rates', 1));
    addRate($this->vehicle, '20', '2026-02-01T00:00')->assertForbidden();
    $this->post(p("resources/{$this->vehicle->id}/meter-events"), [
        'measure' => 'odometer', 'kind' => 'reset', 'reading' => '0', 'occurred_at' => '2026-11-19T09:00', 'reason' => 'x',
    ])->assertForbidden();
    expect(inTenant(fn () => FuelRate::query()->count()))->toBe(1);
});

it('keeps each workspace to its own resources', function () {
    addRate($this->vehicle, '12', '2026-01-01T00:00')->assertSessionHasNoErrors();
    team('globex');
    as_(inTenant(fn () => User::query()->first(), 'globex'), 'logbook_manager', 'globex');
    $this->get(p("resources/{$this->vehicle->id}", 'globex'))->assertNotFound();
    $this->post(p("resources/{$this->vehicle->id}/fuel-rates", 'globex'), [
        'fuel_type' => 'diesel', 'method' => 'distance', 'rate' => '30', 'effective_from' => '2026-01-01T00:00',
    ])->assertNotFound();
    expect(inTenant(fn () => FuelRate::query()->count()))->toBe(1);
});
