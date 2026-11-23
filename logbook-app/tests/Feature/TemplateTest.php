<?php

use App\Logbook\Models\Entry;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Log sheets and their templates (ADR-0025 §2.3–2.4): definitions are
 * checked on the server, published versions never change, and entries
 * keep the version they were written against.
 */

beforeEach(function () {
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook();
});

it('creates a sheet as a draft and publishes version 1', function () {
    $sheet = publishedSheet($this->logbook);

    inTenant(function () use ($sheet) {
        expect($sheet->status)->toBe('active')
            ->and($sheet->publishedVersion->version)->toBe(1)
            ->and($sheet->publishedVersion->status)->toBe('published')
            ->and($sheet->publishedVersion->fields)->toHaveCount(13)
            ->and($sheet->draftVersion)->toBeNull();
    });
});

it('refuses invalid field definitions', function () {
    $this->post(p("logbooks/{$this->logbook->id}/sheets"), ['name' => 'Bad', 'code' => 'BAD'])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', 'BAD')->sole());

    $this->put(p("sheets/{$sheet->id}/draft"), ['fields' => [
        ['name' => 'a', 'label' => 'A', 'type' => 'text'],
        ['name' => 'a', 'label' => 'Again', 'type' => 'integer'],
        ['name' => '9x', 'label' => 'Bad name', 'type' => 'text'],
        ['name' => 'b', 'label' => '', 'type' => 'text'],
        ['name' => 'c', 'label' => 'Choice', 'type' => 'select_one', 'choices' => []],
        ['name' => 'd', 'label' => 'Range', 'type' => 'integer', 'min' => 10, 'max' => 2],
        ['name' => 'e', 'label' => 'Spaceship', 'type' => 'warp_drive'],
        ['name' => 'f', 'label' => 'Default', 'type' => 'integer', 'max' => 5, 'default' => '9'],
    ]])->assertSessionHasErrors([
        'fields.1.name', 'fields.2.name', 'fields.3.label', 'fields.4.choices', 'fields.5.max', 'fields.6.type', 'fields.7.default',
    ]);

    // Nothing to publish: an empty template is refused.
    $this->post(p("sheets/{$sheet->id}/publish"))->assertSessionHasErrors('fields');
    expect(inTenant(fn () => $sheet->fresh()->published_version_id))->toBeNull();
});

it('drops settings a field type does not take', function () {
    $sheet = publishedSheet($this->logbook, fields: [
        ['name' => 'note', 'label' => 'Note', 'type' => 'text', 'min' => 3, 'choices' => [['label' => 'x']], 'max_length' => 50],
        ['name' => 'level', 'label' => 'Level', 'type' => 'integer', 'max_length' => 3, 'min' => '1'],
    ]);

    $fields = inTenant(fn () => $sheet->publishedVersion->fields);
    expect($fields[0])->toBe(['name' => 'note', 'label' => 'Note', 'type' => 'text', 'required' => false, 'max_length' => 50])
        ->and($fields[1])->toBe(['name' => 'level', 'label' => 'Level', 'type' => 'integer', 'required' => false, 'min' => 1]);
});

it('keeps entries on their version when a new one is published', function () {
    $sheet = publishedSheet($this->logbook);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet)->assertSessionHasNoErrors();
    $old = latestEntry();

    // Version 2: the generator field is renamed in its label, a field is added, one removed.
    as_($this->team['manager'], 'logbook_manager');
    $fields = generatorFields();
    $fields[0]['label'] = 'Generator ID';
    array_pop($fields);
    $fields[] = ['name' => 'site', 'label' => 'Site', 'type' => 'text', 'required' => true];
    $this->put(p("sheets/{$sheet->id}/draft"), ['fields' => $fields])->assertSessionHasNoErrors();

    // Drafts do not reach contributors: new entries still use version 1.
    expect(inTenant(fn () => $sheet->fresh()->publishedVersion->version))->toBe(1);

    $this->post(p("sheets/{$sheet->id}/publish"))->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet, generatorValues(['site' => 'Juba']))->assertSessionHasNoErrors();
    $new = latestEntry();

    inTenant(function () use ($old, $new) {
        expect($old->fresh()->version->version)->toBe(1)
            ->and($new->version->version)->toBe(2)
            ->and($new->values['site'])->toBe('Juba')
            ->and(LogSheetVersion::query()->where('version', 1)->sole()->fields[0]['label'])->toBe('Generator');
    });

    // The old entry still reads with its own labels and fields.
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->get(p("entries/{$old->id}"))->assertOk()
        ->assertInertia(fn ($page) => $page->where('entry.version', 1)->where('entry.latestVersion', 2)
            ->where('entry.answers.0.label', 'Generator')->where('entry.answers.0.text', 'GEN-01')
            ->where('entry.answers.12.name', 'photo'));
});

it('refuses to change a field name into another kind of value', function () {
    $sheet = publishedSheet($this->logbook);
    $fields = generatorFields();
    $fields[1]['type'] = 'text';

    $this->post(p("sheets/{$sheet->id}/publish"), ['fields' => $fields])->assertSessionHasErrors('fields.1.name');
});

it('never changes a published version, even directly in the database', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The immutability trigger is PostgreSQL only.');
    }
    $sheet = publishedSheet($this->logbook);

    expect(fn () => DB::transaction(fn () => inTenant(fn () => LogSheetVersion::query()->whereKey($sheet->published_version_id)->update(['fields' => '[]']))))
        ->toThrow(QueryException::class, 'immutable');
});

it('copies a starter template as a draft to change and publish', function () {
    $this->get(p("logbooks/{$this->logbook->id}/starters"))->assertOk()
        ->assertInertia(fn ($page) => $page->has('templates', 9));

    $this->post(p("logbooks/{$this->logbook->id}/starters"), ['template' => 'vehicle_movement', 'name' => 'Fleet trips', 'code' => 'veh'])
        ->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', 'VEH')->sole());

    inTenant(function () use ($sheet) {
        expect($sheet->status)->toBe('draft')
            ->and($sheet->name)->toBe('Fleet trips')
            ->and($sheet->draftVersion->fields)->toHaveCount(9)
            // ADR-0031: the vehicle is a registered asset, recorded per trip with a fuel estimate.
            ->and($sheet->draftVersion->fields[0]['type'])->toBe('resource')
            ->and($sheet->draftVersion->recording['mode'])->toBe('event')
            ->and($sheet->draftVersion->recording['activity']['measure'])->toBe('odometer');
    });
    $this->post(p("sheets/{$sheet->id}/publish"))->assertSessionHasNoErrors();
    expect(inTenant(fn () => $sheet->fresh()->status))->toBe('active');

    // Every starter is valid as given.
    foreach (['daily_activity' => 'ACT', 'generator_operation' => 'GENR', 'it_maintenance' => 'ITM', 'temperature_monitoring' => 'TMP', 'equipment_inspection' => 'INS',
        'equipment_maintenance' => 'MNT', 'water_pump_operation' => 'PMP', 'incident_report' => 'INC'] as $key => $code) {
        $this->post(p("logbooks/{$this->logbook->id}/starters"), ['template' => $key, 'name' => $key, 'code' => $code])->assertSessionHasNoErrors();
        $starter = inTenant(fn () => LogSheet::query()->where('code', $code)->sole());
        $this->post(p("sheets/{$starter->id}/publish"))->assertSessionHasNoErrors();
    }
});

it('refuses a code another sheet uses, whatever its case', function () {
    publishedSheet($this->logbook);

    $this->post(p("logbooks/{$this->logbook->id}/sheets"), ['name' => 'Again', 'code' => 'gen'])->assertSessionHasErrors('code');
    $this->post(p("logbooks/{$this->logbook->id}/sheets"), ['name' => 'Bad', 'code' => '1AB'])->assertSessionHasErrors('code');
});

it('duplicates a sheet without its entries, and archives and restores', function () {
    $sheet = publishedSheet($this->logbook);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet)->assertSessionHasNoErrors();

    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("sheets/{$sheet->id}/duplicate"), ['name' => 'Generator B', 'code' => 'GENB', 'logbook_id' => $this->logbook->id])->assertSessionHasNoErrors();
    $copy = inTenant(fn () => LogSheet::query()->where('code', 'GENB')->sole());
    expect(inTenant(fn () => Entry::query()->where('log_sheet_id', $copy->id)->count()))->toBe(0)
        ->and(inTenant(fn () => $copy->draftVersion->fields))->toHaveCount(13);

    $this->post(p("sheets/{$sheet->id}/archive"))->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet)->assertNotFound();
    // Its entries stay readable.
    $this->get(p('entries/'.latestEntry()->id))->assertOk();

    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("sheets/{$sheet->id}/restore"))->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet)->assertSessionHasNoErrors();
});

it('lets only managers configure', function () {
    $sheet = publishedSheet($this->logbook);

    foreach (['writer' => 'logbook_contributor', 'reviewer' => 'logbook_reviewer', 'viewer' => 'logbook_viewer'] as $who => $role) {
        as_($this->team[$who], $role);
        $this->post(p('logbooks'), ['name' => 'Mine'])->assertForbidden();
        $this->post(p("logbooks/{$this->logbook->id}/sheets"), ['name' => 'X', 'code' => 'XX'])->assertForbidden();
        $this->get(p("sheets/{$sheet->id}/template"))->assertForbidden();
        $this->post(p("sheets/{$sheet->id}/publish"))->assertForbidden();
        $this->post(p("logbooks/{$this->logbook->id}/archive"))->assertForbidden();
    }
});

it('archives a logbook without losing its records', function () {
    $sheet = publishedSheet($this->logbook);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet)->assertSessionHasNoErrors();

    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("logbooks/{$this->logbook->id}/archive"))->assertSessionHasNoErrors();

    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet)->assertNotFound();
    $this->get(p('entries'))->assertInertia(fn ($page) => $page->where('entries.total', 1));
    // No route deletes a logbook.
    $this->delete(p("logbooks/{$this->logbook->id}"))->assertStatus(405);
});
