<?php

use App\Logbook\Models\Entry;
use App\Logbook\Models\EntryRevision;
use App\Logbook\Models\LogSheet;
use App\Logbook\References;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Entries: values checked on the server against the template version,
 * drafts private to their author, idempotent creation, references.
 */

beforeEach(function () {
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook();
    $this->sheet = publishedSheet($this->logbook);
    as_($this->team['writer'], 'logbook_contributor');
});

it('submits an entry with a reference and a first revision', function () {
    writeEntry($this->sheet, generatorValues(['operator' => $this->team['other']->id, 'checks' => ['coolant', 'oil'], 'serviced' => true]))
        ->assertSessionHasNoErrors()->assertRedirect();
    $entry = latestEntry();

    expect($entry->status)->toBe('submitted')
        ->and($entry->reference)->toBe('GEN-'.now()->year.'-000001')
        ->and($entry->revision)->toBe(1)
        ->and($entry->review_required)->toBeTrue()
        ->and($entry->values)->toMatchArray(['generator' => 'GEN-01', 'hours' => '6.5', 'fuel' => '40', 'checks' => ['oil', 'coolant'], 'serviced' => true])
        ->and($entry->search_text)->toContain('gen-01')->toContain('good')
        ->and(inTenant(fn () => EntryRevision::query()->where('log_entry_id', $entry->id)->count()))->toBe(1);
});

it('refuses missing required values and invalid ones, field by field', function () {
    writeEntry($this->sheet, ['generator' => str_repeat('x', 21), 'hours' => '25', 'condition' => 'exploded', 'starts' => '1.5',
        'checks' => ['oil', 'fuel'], 'run_date' => '2026-02-30', 'started_at' => '25:00', 'serviced' => 'maybe',
        'operator' => (string) Str::uuid(), 'logged_at' => 'tomorrow'])
        ->assertSessionHasErrors(['values.generator', 'values.hours', 'values.condition', 'values.starts', 'values.checks',
            'values.run_date', 'values.started_at', 'values.serviced', 'values.operator', 'values.logged_at']);

    writeEntry($this->sheet, ['fuel' => '3'])->assertSessionHasErrors(['values.generator', 'values.hours', 'values.condition']);

    expect(inTenant(fn () => Entry::query()->count()))->toBe(0);
});

it('drops values for fields the template does not have, and never takes files from the request', function () {
    writeEntry($this->sheet, generatorValues(['hacked' => 'yes', 'photo' => [(string) Str::uuid()]]))->assertSessionHasNoErrors();

    expect(latestEntry()->values)->not->toHaveKey('hacked')->not->toHaveKey('photo');
});

it('saves drafts without required values, which only their author sees', function () {
    writeEntry($this->sheet, ['generator' => 'GEN-02'], submit: false)->assertSessionHasNoErrors();
    $draft = latestEntry();
    expect($draft->status)->toBe('draft')->and($draft->reference)->toBeNull();

    // Still type-checked.
    writeEntry($this->sheet, ['hours' => 'many'], submit: false)->assertSessionHasErrors('values.hours');

    foreach (['other' => 'logbook_contributor', 'reviewer' => 'logbook_reviewer', 'manager' => 'logbook_manager'] as $who => $role) {
        as_($this->team[$who], $role);
        $this->get(p("entries/{$draft->id}"))->assertNotFound();
    }

    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p("entries/{$draft->id}/edit"))->assertOk();
    $this->put(p("entries/{$draft->id}"), ['occurred_at' => '2026-11-19T10:00', 'values' => generatorValues(), 'submit' => true])->assertSessionHasNoErrors();
    expect($draft->fresh()->status)->toBe('submitted');
});

it('discards a never-submitted draft, and nothing else', function () {
    writeEntry($this->sheet, ['generator' => 'G'], submit: false);
    $draft = latestEntry();
    $this->delete(p("entries/{$draft->id}"))->assertRedirect();
    expect(inTenant(fn () => Entry::query()->whereKey($draft->id)->exists()))->toBeFalse();

    writeEntry($this->sheet)->assertSessionHasNoErrors();
    $submitted = latestEntry();
    $this->delete(p("entries/{$submitted->id}"))->assertSessionHasErrors('entry');
    expect(inTenant(fn () => Entry::query()->whereKey($submitted->id)->exists()))->toBeTrue();
});

it('makes one entry however often the same request is sent', function () {
    $key = (string) Str::uuid7();
    writeEntry($this->sheet, key: $key)->assertSessionHasNoErrors();
    writeEntry($this->sheet, key: $key)->assertSessionHasNoErrors();
    writeEntry($this->sheet, generatorValues(['generator' => 'other']), key: $key)->assertSessionHasNoErrors();

    expect(inTenant(fn () => Entry::query()->count()))->toBe(1)
        ->and(latestEntry()->values['generator'])->toBe('GEN-01')
        ->and(latestEntry()->revision)->toBe(1);

    // The key belongs to its author: someone else's same key is a new entry.
    as_($this->team['other'], 'logbook_contributor');
    writeEntry($this->sheet, key: $key)->assertSessionHasNoErrors();
    expect(inTenant(fn () => Entry::query()->count()))->toBe(2);
});

it('numbers entries per sheet code and year, without gaps from drafts', function () {
    writeEntry($this->sheet)->assertSessionHasNoErrors();
    writeEntry($this->sheet, submit: false);
    $draft = latestEntry();
    $this->delete(p("entries/{$draft->id}"));
    writeEntry($this->sheet)->assertSessionHasNoErrors();

    as_($this->team['manager'], 'logbook_manager');
    $vehicles = publishedSheet($this->logbook, 'VEH', fields: [['name' => 'vehicle', 'label' => 'Vehicle', 'type' => 'text', 'required' => true]]);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($vehicles, ['vehicle' => 'SSD 123'])->assertSessionHasNoErrors();

    $year = now()->year;
    expect(inTenant(fn () => Entry::query()->orderBy('reference')->pluck('reference')->all()))
        ->toBe(["GEN-{$year}-000001", "GEN-{$year}-000002", "VEH-{$year}-000001"]);
});

it('draws references from a locked sequence, never from the highest id', function () {
    $references = inTenant(fn () => collect(range(1, 25))->map(fn () => DB::transaction(fn () => References::next('LOG')))->all());

    expect($references)->toHaveCount(25)->and(array_unique($references))->toHaveCount(25)
        ->and($references[24])->toBe('LOG-'.now()->year.'-000025');
});

it('refuses writing without the contributor permission, and on unpublished sheets', function () {
    as_($this->team['viewer'], 'logbook_viewer');
    writeEntry($this->sheet)->assertForbidden();

    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("logbooks/{$this->logbook->id}/sheets"), ['name' => 'Unpublished', 'code' => 'UNP'])->assertSessionHasNoErrors();
    $unpublished = inTenant(fn () => LogSheet::query()->where('code', 'UNP')->sole());

    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($unpublished, ['x' => 1])->assertNotFound();
    $this->get(p('entries/new?sheet='.$unpublished->id))->assertNotFound();
});

it('lists my entries and searches by keyword and reference', function () {
    writeEntry($this->sheet, generatorValues(['notes' => 'Replaced the oil filter']))->assertSessionHasNoErrors();
    writeEntry($this->sheet, generatorValues(['generator' => 'GEN-77']))->assertSessionHasNoErrors();
    as_($this->team['other'], 'logbook_contributor');
    writeEntry($this->sheet, generatorValues(['generator' => 'GEN-99']))->assertSessionHasNoErrors();

    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('entries'))->assertInertia(fn ($page) => $page->component('entries/index')->where('entries.total', 2));
    $this->get(p('entries?q=OIL+filter'))->assertInertia(fn ($page) => $page->where('entries.total', 1));
    // A contributor never reads a colleague's entries.
    $this->get(p('entries?scope=all&q=gen-99'))->assertInertia(fn ($page) => $page->where('entries.total', 0));
    $this->get(p('entries?scope=all&q=GEN-'.now()->year.'-000002'))->assertInertia(fn ($page) => $page->where('entries.total', 1));
    $this->get(p('entries?scope=all&status=submitted&from=2026-11-19&to=2026-11-19'))->assertInertia(fn ($page) => $page->where('entries.total', 2));
    $this->get(p('entries?scope=all&from=2026-11-20'))->assertInertia(fn ($page) => $page->where('entries.total', 0));

    // A viewer reads everyone's.
    as_($this->team['viewer'], 'logbook_viewer');
    $this->get(p('entries?scope=all&q=gen-99'))->assertInertia(fn ($page) => $page->where('entries.total', 1)->where('entries.data.0.creator', 'Other'));
    $this->get(p('entries?scope=all&status=submitted&from=2026-11-19&to=2026-11-19'))->assertInertia(fn ($page) => $page->where('entries.total', 3));
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('entries?status=nonsense'))->assertSessionHasErrors('status');
});
