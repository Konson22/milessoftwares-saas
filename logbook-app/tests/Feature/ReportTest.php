<?php

/*
 * Reports and exports: what the member may read, nothing more; numeric
 * summaries; CSV cells never run as spreadsheet formulas.
 */

beforeEach(function () {
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook();
    $this->sheet = publishedSheet($this->logbook);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($this->sheet, generatorValues(['hours' => '6', 'fuel' => '40', 'starts' => '2']))->assertSessionHasNoErrors();
    writeEntry($this->sheet, generatorValues(['hours' => '2.5', 'fuel' => '10.25']))->assertSessionHasNoErrors();
    writeEntry($this->sheet, generatorValues(['hours' => '9']), submit: false)->assertSessionHasNoErrors();
    as_($this->team['other'], 'logbook_contributor');
    writeEntry($this->sheet, generatorValues(['hours' => '1', 'fuel' => '5', 'generator' => '+cmd|calc', 'notes' => '=HYPERLINK("http://evil","x")']))->assertSessionHasNoErrors();
});

it('counts by status, day and contributor, without drafts', function () {
    as_($this->team['viewer'], 'logbook_viewer');
    $this->get(p('reports'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('counts.total', 3)
        ->where('counts.byStatus.submitted', 3)
        ->where('counts.byDay.0', ['day' => '2026-11-19', 'count' => 3])
        ->where('counts.byContributor.0', ['name' => 'Writer', 'count' => 2])
        ->where('numbers', null));
});

it('sums numeric fields of a sheet across its entries', function () {
    as_($this->team['viewer'], 'logbook_viewer');
    $this->get(p('reports?sheet='.$this->sheet->id))->assertInertia(fn ($page) => $page
        ->where('numbers.0', ['name' => 'hours', 'label' => 'Running hours', 'count' => 3, 'sum' => 9.5, 'average' => 3.1667, 'min' => 1, 'max' => 6])
        ->where('numbers.1.sum', 55.25)
        ->where('numbers.2', ['name' => 'starts', 'label' => 'Starts', 'count' => 1, 'sum' => 2, 'average' => 2, 'min' => 2, 'max' => 2]));

    $this->get(p('reports?sheet='.$this->sheet->id.'&creator='.$this->team['other']->id))
        ->assertInertia(fn ($page) => $page->where('counts.total', 1)->where('numbers.0.sum', 1));
});

it('exports CSV with one column per field, formulas neutralized', function () {
    as_($this->team['viewer'], 'logbook_viewer');
    $csv = $this->get(p('reports/entries.csv?sheet='.$this->sheet->id))->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), array_filter(explode("\n", trim(substr($csv, 3)))));

    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toContain('Reference', 'Running hours', 'Fuel (litres)', 'Condition')
        ->and($csv)->toContain("'=HYPERLINK")->toContain("'+cmd|calc")
        ->and($csv)->not->toContain(',=HYPERLINK')
        ->and($csv)->toContain('Good');
});

it('exports a PDF listing and a single entry as PDF', function () {
    as_($this->team['viewer'], 'logbook_viewer');
    $this->get(p('reports/entries.pdf?sheet='.$this->sheet->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $entry = latestEntry();
    $this->get(p("entries/{$entry->id}/pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('needs the reports permission, and stays within reach', function () {
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('reports'))->assertForbidden();
    $this->get(p('reports/entries.csv'))->assertForbidden();
    $this->get(p('reports/entries.pdf'))->assertForbidden();

    // A viewer not listed on a restricted logbook sees none of it.
    as_($this->team['manager'], 'logbook_manager');
    $this->put(p("logbooks/{$this->logbook->id}"), ['name' => 'Facility Management', 'restricted' => true])->assertSessionHasNoErrors();
    as_($this->team['viewer'], 'logbook_viewer');
    $this->get(p('reports'))->assertInertia(fn ($page) => $page->where('counts.total', 0));
    $this->get(p('reports/entries.csv?sheet='.$this->sheet->id))->assertNotFound();
    expect($this->get(p('reports/entries.csv'))->streamedContent())->not->toContain('GEN-');
});

it('refuses malformed filters', function () {
    as_($this->team['viewer'], 'logbook_viewer');
    $this->get(p('reports?from=yesterday'))->assertSessionHasErrors('from');
    $this->get(p('reports?from=2026-11-20&to=2026-11-01'))->assertSessionHasErrors('to');
    $this->get(p('reports?sheet=not-a-uuid'))->assertSessionHasErrors('sheet');
});
