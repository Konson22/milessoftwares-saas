<?php

use App\Logbook\Models\Task;

/*
 * The issues report: abnormal readings, returned entries and overdue tasks
 * counted per department, logbook and person, worst first, within what the
 * viewer reaches.
 */

it('counts issues by department, logbook and person, worst first', function () {
    $team = team();
    as_($team['manager'], 'logbook_manager');
    $logbook = logbook('Generators');
    $quiet = logbook('Gate log');
    $fields = generatorFields();
    $fields[1] = [...$fields[1], 'expected_min' => '1', 'expected_max' => '12', 'raise_exception' => true, 'exception_severity' => 'high'];
    $sheet = publishedSheet($logbook, fields: $fields);
    $gate = publishedSheet($quiet, 'GATE');

    as_($team['writer'], 'logbook_contributor');
    writeEntry($sheet, generatorValues(['hours' => '20']))->assertSessionHasNoErrors();
    as_($team['other'], 'logbook_contributor');
    writeEntry($gate, generatorValues(['hours' => '3']))->assertSessionHasNoErrors();

    as_($team['manager'], 'logbook_manager');
    $this->post(p('tasks'), ['title' => 'Late task', 'assignee_id' => $team['other']->id, 'logbook_id' => $quiet->id, 'due_at' => '2026-01-01T08:00'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => Task::query()->count()))->toBe(1);

    $this->get(p('reports'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('issues.logbooks.0.name', 'Generators')
        ->where('issues.logbooks.0.serious', 1)
        ->where('issues.logbooks.0.level', 'investigate')
        ->where('issues.logbooks.1.name', 'Gate log')
        ->where('issues.logbooks.1.overdue', 1)
        ->where('issues.logbooks.1.level', 'watch')
        ->where('issues.people.0.name', $team['writer']->name)
        ->where('issues.people.0.open', 1)
        ->where('issues.departments.0.logbooks', 2)
        ->where('issues.departments.0.entries', 2));

    // The logbook page shows the same open issues for that logbook.
    $this->get(p("logbooks/{$logbook->id}"))->assertOk()->assertInertia(fn ($page) => $page
        ->where('stats.openExceptions', 1)->where('stats.seriousExceptions', 1)->where('stats.awaiting', 1)->where('stats.overdueTasks', 0));
    $this->get(p("sheets/{$sheet->id}"))->assertOk()->assertInertia(fn ($page) => $page
        ->where('stats.total', 1)->where('stats.awaiting', 1)->where('stats.seriousExceptions', 1)
        ->where('fields.1.range', ['1', '12'])->has('stats.lastEntryAt'));
    $this->get(p("logbooks/{$quiet->id}"))->assertInertia(fn ($page) => $page->where('stats.overdueTasks', 1)->where('stats.openExceptions', 0));
});

it('leaves out logbooks the viewer does not reach', function () {
    $team = team();
    as_($team['manager'], 'logbook_manager');
    $secret = logbook('Secret', ['restricted' => true]);
    $fields = generatorFields();
    $fields[1] = [...$fields[1], 'expected_min' => '1', 'expected_max' => '12', 'raise_exception' => true];
    $sheet = publishedSheet($secret, fields: $fields);
    addMember($secret, $team['writer'], 'contributor');
    as_($team['writer'], 'logbook_contributor');
    writeEntry($sheet, generatorValues(['hours' => '20']))->assertSessionHasNoErrors();

    as_($team['viewer'], 'logbook_viewer');
    $this->get(p('reports'))->assertOk()->assertInertia(fn ($page) => $page->has('issues.logbooks', 0)->has('issues.people', 0));
});
