<?php

use App\Logbook\Models\Entry;
use App\Logbook\Models\Location;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/*
 * ADR-0029 phase 1: the report's combined filters, its table, timeline,
 * trends and map views, and one record opened over it. Always within what
 * the viewer may read; nothing on the original records changes.
 */

beforeEach(function () {
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook('Equipment');
    $this->post(p('locations'), ['name' => 'North site'])->assertSessionHasNoErrors();
    $north = inTenant(fn () => Location::query()->where('name', 'North site')->sole());
    $this->post(p('resources'), ['code' => 'GEN-001', 'name' => 'Main generator', 'type' => 'Generator', 'location_id' => $north->id])->assertSessionHasNoErrors();
    $this->post(p('resources'), ['code' => 'GEN-002', 'name' => 'Backup generator', 'type' => 'Generator'])->assertSessionHasNoErrors();
    $this->gen1 = inTenant(fn () => LogResource::query()->where('code', 'GEN-001')->sole());
    $this->gen2 = inTenant(fn () => LogResource::query()->where('code', 'GEN-002')->sole());
    $this->north = $north;
    $this->sheet = publishedSheet($this->logbook, 'GOL', fields: [
        ['name' => 'unit', 'label' => 'Generator', 'type' => 'resource', 'required' => true],
        ['name' => 'hours', 'label' => 'Running hours', 'type' => 'decimal', 'required' => true, 'expected_min' => '0', 'expected_max' => '12', 'raise_exception' => true, 'exception_severity' => 'high', 'unit' => 'h'],
        ['name' => 'operator', 'label' => 'Operator', 'type' => 'user', 'required' => false],
        ['name' => 'occurred', 'label' => 'When', 'type' => 'datetime', 'required' => false],
    ]);

    as_($this->team['writer'], 'logbook_contributor');
    Date::setTestNow('2026-11-18 09:00:00');
    reportEntryAt($this->sheet, ['unit' => $this->gen1->id, 'hours' => '6'], '2026-11-18T08:00')->assertSessionHasNoErrors();
    $this->first = latestEntry();
    Date::setTestNow('2026-11-19 09:00:00');
    reportEntryAt($this->sheet, ['unit' => $this->gen1->id, 'hours' => '20', 'operator' => $this->team['other']->id], '2026-11-19T08:00')->assertSessionHasNoErrors();
    $this->abnormal = latestEntry();
    as_($this->team['other'], 'logbook_contributor');
    Date::setTestNow('2026-11-21 23:30:00');
    reportEntryAt($this->sheet, ['unit' => $this->gen2->id, 'hours' => '4'], '2026-11-18T22:00')->assertSessionHasNoErrors();
    $this->late = latestEntry();
    Date::setTestNow();
});

function reportEntryAt($sheet, array $values, string $at)
{
    return test()->post(p('entries'), [
        'sheet_id' => $sheet->id, 'occurred_at' => $at, 'values' => $values,
        'idempotency_key' => (string) Str::uuid7(), 'submit' => true,
    ]);
}

function refs($page, string $prop = 'table.rows.data'): array
{
    return collect(data_get($page->toArray()['props'], $prop))->pluck('reference')->sort()->values()->all();
}

it('combines item, place, person, relation, date basis and flag filters', function () {
    as_($this->team['manager'], 'logbook_manager');
    $all = fn (array $q) => $this->get(p('reports?'.http_build_query(['view' => 'table', ...$q])))->assertOk();

    // By item, and by the place the item is kept (GEN-002 has none).
    $all(['resource' => $this->gen1->id])->assertInertia(fn ($page) => $page->where('counts.total', 2));
    $all(['location' => $this->north->id])->assertInertia(fn ($page) => $page->where('counts.total', 2));
    // A person: as writer, or named in a person field.
    $all(['person' => $this->team['other']->id, 'relation' => 'created'])->assertInertia(fn ($page) => $page->where('counts.total', 1));
    $all(['person' => $this->team['other']->id, 'relation' => 'named'])->assertInertia(fn ($page) => $page->where('counts.total', 1)->where('table.rows.data.0.id', $this->abnormal->id));
    $all(['person' => $this->team['other']->id])->assertInertia(fn ($page) => $page->where('counts.total', 2));
    // The same day by when it happened, or by when it was submitted (two days later).
    $all(['from' => '2026-11-18', 'to' => '2026-11-18'])->assertInertia(fn ($page) => $page->where('counts.total', 2));
    $all(['from' => '2026-11-18', 'to' => '2026-11-18', 'basis' => 'submitted'])->assertInertia(fn ($page) => $page->where('counts.total', 1));
    // Abnormal readings only, together with the item.
    $all(['flag' => 'abnormal', 'resource' => $this->gen1->id])->assertInertia(fn ($page) => $page->where('counts.total', 1)->where('table.rows.data.0.id', $this->abnormal->id));
    $all(['flag' => 'abnormal', 'resource' => $this->gen2->id])->assertInertia(fn ($page) => $page->where('counts.total', 0));
});

it('uses the workspace day for date boundaries', function () {
    inTenant(fn () => Workspace::query()->create(['display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));
    as_($this->team['manager'], 'logbook_manager');
    // The entries were written as UTC times; 18 Nov 22:00 UTC is already the 19th in Juba (UTC+2).
    $this->get(p('reports?view=table&from=2026-11-18&to=2026-11-18'))->assertInertia(fn ($page) => $page->where('counts.total', 1));
    $this->get(p('reports?view=table&from=2026-11-19&to=2026-11-19'))->assertInertia(fn ($page) => $page->where('counts.total', 2));
});

it('shows the sheet fields as table columns, flagging out-of-range readings', function () {
    as_($this->team['manager'], 'logbook_manager');
    $this->get(p('reports?view=table&sheet='.$this->sheet->id))->assertInertia(function ($page) {
        $page->where('table.columns.1.label', 'Running hours');
        $rows = collect($page->toArray()['props']['table']['rows']['data']);
        $row = $rows->firstWhere('id', $this->abnormal->id);
        expect($row['cells'][1])->toBe(['text' => '20', 'flag' => 'high'])
            ->and($row['cells'][0]['text'])->toBe('GEN-001 · Main generator');
    });
});

it('orders the timeline newest first and names each clock', function () {
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->post(p("entries/{$this->abnormal->id}/review"), ['decision' => 'returned', 'comment' => 'Check the meter.', 'revision' => 1])->assertSessionHasNoErrors();
    as_($this->team['manager'], 'logbook_manager');

    $this->get(p('reports?view=timeline&resource='.$this->gen1->id))->assertOk()->assertInertia(function ($page) {
        $events = collect($page->toArray()['props']['timeline']['events']);
        $times = $events->pluck('at')->all();
        $sorted = $times;
        rsort($sorted);
        expect($times)->toBe($sorted)
            ->and($events->pluck('kind')->unique()->values()->all())->toContain('entry.occurred', 'entry.submitted', 'review.returned', 'exception.raised')
            ->and($events->firstWhere('kind', 'entry.occurred')['basis'])->toBe('occurred')
            ->and($events->firstWhere('kind', 'review.returned')['basis'])->toBe('recorded')
            // GEN-002's entry is not about this item.
            ->and($events->where('subject.id', $this->late->id)->all())->toBe([]);
    });
});

it('trends a number field per day, by the template version of each entry', function () {
    as_($this->team['manager'], 'logbook_manager');
    $this->get(p('reports?view=trends&sheet='.$this->sheet->id))->assertInertia(function ($page) {
        $hours = collect($page->toArray()['props']['trends'])->firstWhere('name', 'hours');
        expect($hours['unit'])->toBe('h')
            ->and($hours['days'])->toHaveCount(2)
            ->and($hours['days'][0])->toMatchArray(['day' => '2026-11-18', 'count' => 2, 'sum' => 10.0])
            ->and($hours['days'][1])->toMatchArray(['day' => '2026-11-19', 'sum' => 20.0]);
    });
    // Trends need one sheet.
    $this->get(p('reports?view=trends'))->assertInertia(fn ($page) => $page->where('trends', null));
});

it('opens a record with its revisions, reviews, exceptions and actions', function () {
    as_($this->team['manager'], 'logbook_manager');
    // Opened over the report (the modal request), and straight from a link (the report underneath).
    $props = $this->withHeaders(['X-Inertia' => 'true', 'X-InertiaUI-Modal' => 'm1'])->get(p("reports/records/{$this->abnormal->id}"))->assertOk()->json('props');
    expect($props['entry']['reference'])->toBe($this->abnormal->reference)
        ->and($props['resources'][0]['label'])->toBe('GEN-001 · Main generator')
        ->and($props['exceptions'][0]['severity'])->toBe('high')
        ->and($props['revisions'])->toHaveCount(1)
        ->and($props['can']['createTask'])->toBeTrue()
        ->and($props['can']['investigate'])->toBeTrue()
        ->and(collect($props['entry']['answers'])->firstWhere('name', 'hours')['outOfRange'])->not->toBeNull();
    $this->flushHeaders()->get(p("reports/records/{$this->abnormal->id}"))->assertOk()->assertSee('reports\\/index', false);
});

it('never opens a record or counts entries the viewer cannot read', function () {
    as_($this->team['manager'], 'logbook_manager');
    $secret = logbook('Secret', ['restricted' => true]);
    $sheet = publishedSheet($secret, 'SEC', review: false);
    addMember($secret, $this->team['writer'], 'contributor');
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet)->assertSessionHasNoErrors();
    $hidden = latestEntry();

    as_($this->team['viewer'], 'logbook_viewer');
    $this->withHeaders(['X-Inertia' => 'true', 'X-InertiaUI-Modal' => 'm1'])->get(p("reports/records/{$hidden->id}"))->assertNotFound();
    $this->flushHeaders();
    $this->get(p('reports?view=timeline'))->assertInertia(fn ($page) => expect(collect($page->toArray()['props']['timeline']['events'])->where('subject.id', $hidden->id)->all())->toBe([]));
    $this->get(p('reports?view=table'))->assertInertia(fn ($page) => expect(collect($page->toArray()['props']['table']['rows']['data'])->pluck('id'))->not->toContain($hidden->id));
});

it('keeps another workspace entirely out', function () {
    team('globex');
    as_(inTenant(fn () => User::query()->first(), 'globex'), 'logbook_manager', 'globex');
    $this->get(p('reports?view=table&resource='.$this->gen1->id, 'globex'))->assertOk()->assertInertia(fn ($page) => $page->where('counts.total', 0));
    $this->withHeaders(['X-Inertia' => 'true', 'X-InertiaUI-Modal' => 'm1'])->get(p("reports/records/{$this->first->id}", 'globex'))->assertNotFound();
});

it('changes nothing on the records it reads', function () {
    $before = inTenant(fn () => Entry::query()->orderBy('id')->get()->map(fn ($e) => [$e->id, $e->status, $e->revision, $e->values, $e->updated_at->toIso8601String()])->all());
    $exceptions = inTenant(fn () => LogException::query()->count());
    as_($this->team['manager'], 'logbook_manager');
    foreach (['overview', 'table', 'timeline', 'trends', 'map'] as $view) {
        $this->get(p("reports?view={$view}&sheet=".$this->sheet->id))->assertOk();
    }
    $this->get(p("reports/records/{$this->abnormal->id}"))->assertOk();

    expect(inTenant(fn () => Entry::query()->orderBy('id')->get()->map(fn ($e) => [$e->id, $e->status, $e->revision, $e->values, $e->updated_at->toIso8601String()])->all()))->toBe($before)
        ->and(inTenant(fn () => LogException::query()->count()))->toBe($exceptions);
});
