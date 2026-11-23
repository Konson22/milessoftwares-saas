<?php

use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Investigation;
use App\Logbook\Models\InvestigationEvidence;
use App\Logbook\Models\InvestigationNote;
use App\Logbook\Models\LogException;
use App\Logbook\Models\Task;
use App\Models\User;

/*
 * ADR-0029 phase 2: investigations link to the original records, keep
 * every wording and every link (removal is a mark), move through a small
 * lifecycle, and never open evidence the viewer could not open anyway.
 */

beforeEach(function () {
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook('Equipment');
    $fields = generatorFields();
    $fields[1] = [...$fields[1], 'expected_min' => '1', 'expected_max' => '12', 'raise_exception' => true, 'exception_severity' => 'high'];
    $this->sheet = publishedSheet($this->logbook, fields: $fields);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($this->sheet, generatorValues(['hours' => '20']))->assertSessionHasNoErrors();
    $this->entry = latestEntry();
    as_($this->team['manager'], 'logbook_manager');
});

function openInvestigation(array $extra = []): Investigation
{
    test()->post(p('investigations'), ['title' => 'Generator overrun', 'logbook_id' => test()->logbook->id, ...$extra])->assertSessionHasNoErrors();

    return inTenant(fn () => Investigation::query()->latest()->firstOrFail());
}

it('opens with a reference, linked to the entry it started from', function () {
    $investigation = openInvestigation(['entry_id' => $this->entry->id, 'description' => 'Hours far above normal.']);

    expect($investigation->reference)->toStartWith('INV-2026-')
        ->and($investigation->status)->toBe('open')
        ->and($investigation->investigator_id)->toBe($this->team['manager']->id);
    $link = inTenant(fn () => InvestigationEvidence::query()->sole());
    expect($link->kind)->toBe('entry')->and($link->entry_revision)->toBe(1)->and($link->label)->toBe($this->entry->reference.' · GEN log');

    $this->get(p("investigations/{$investigation->id}"))->assertOk()->assertInertia(fn ($page) => $page->component('investigations/show')
        ->where('evidence.0.restricted', false)->where('evidence.0.subjectId', $this->entry->id)
        ->where('timeline.0.basis', 'occurred'));
});

it('links the exact entry revision, and keeps it when the entry is corrected', function () {
    $investigation = openInvestigation();
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->post(p("entries/{$this->entry->id}/review"), ['decision' => 'returned', 'comment' => 'Check the meter.', 'revision' => 1])->assertSessionHasNoErrors();
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("investigations/{$investigation->id}/evidence"), ['kind' => 'entry', 'subject_id' => $this->entry->id])->assertSessionHasNoErrors();

    // The author corrects and resubmits: revision 2.
    as_($this->team['writer'], 'logbook_contributor');
    $this->put(p("entries/{$this->entry->id}"), ['occurred_at' => '2026-11-19T08:30', 'values' => generatorValues(['hours' => '10']), 'submit' => true, 'revision' => 1])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $this->entry->fresh()->revision))->toBe(2);

    as_($this->team['manager'], 'logbook_manager');
    expect(inTenant(fn () => InvestigationEvidence::query()->sole()->entry_revision))->toBe(1);
    // Revision 2 can be linked next to it; the same revision twice cannot.
    $this->post(p("investigations/{$investigation->id}/evidence"), ['kind' => 'entry', 'subject_id' => $this->entry->id])->assertSessionHasNoErrors();
    $this->post(p("investigations/{$investigation->id}/evidence"), ['kind' => 'entry', 'subject_id' => $this->entry->id, 'revision' => 2])->assertSessionHasErrors('subject_id');
    $this->post(p("investigations/{$investigation->id}/evidence"), ['kind' => 'entry', 'subject_id' => $this->entry->id, 'revision' => 3])->assertSessionHasErrors('revision');
    expect(inTenant(fn () => InvestigationEvidence::query()->orderBy('entry_revision')->pluck('entry_revision')->all()))->toBe([1, 2]);
});

it('links exceptions by reference and marks removed evidence instead of deleting it', function () {
    $investigation = openInvestigation();
    $exception = inTenant(fn () => LogException::query()->sole());
    $this->post(p("investigations/{$investigation->id}/evidence"), ['kind' => 'exception', 'reference' => strtolower($exception->reference)])->assertSessionHasNoErrors();
    $link = inTenant(fn () => InvestigationEvidence::query()->sole());

    $this->post(p("investigations/{$investigation->id}/evidence/{$link->id}/remove"), ['reason' => 'Wrong generator'])->assertSessionHasNoErrors();
    $kept = inTenant(fn () => $link->fresh());
    expect($kept->removed_at)->not->toBeNull()->and($kept->removal_reason)->toBe('Wrong generator')
        ->and(inTenant(fn () => InvestigationEvidence::query()->count()))->toBe(1);
    $this->post(p("investigations/{$investigation->id}/evidence"), ['kind' => 'exception', 'reference' => 'EXC-2026-999999'])->assertSessionHasErrors('reference');
});

it('keeps every wording of a note', function () {
    $investigation = openInvestigation();
    $this->post(p("investigations/{$investigation->id}/notes"), ['kind' => 'finding', 'body' => 'The hour meter was read wrongly.'])->assertSessionHasNoErrors();
    $note = inTenant(fn () => InvestigationNote::query()->sole());
    $this->put(p("investigations/{$investigation->id}/notes/{$note->id}"), ['body' => 'The hour meter is faulty: it jumps.'])->assertSessionHasNoErrors();
    // The old wording cannot be edited again.
    $this->put(p("investigations/{$investigation->id}/notes/{$note->id}"), ['body' => 'x'])->assertSessionHasErrors('body');

    $notes = inTenant(fn () => InvestigationNote::query()->orderBy('version')->get());
    expect($notes->pluck('body')->all())->toBe(['The hour meter was read wrongly.', 'The hour meter is faulty: it jumps.'])
        ->and($notes[0]->superseded_at)->not->toBeNull()
        ->and($notes[1]->supersedes_id)->toBe($note->id);
});

it('moves through its lifecycle, closes with a decision and reopens with a reason', function () {
    $investigation = openInvestigation();
    $this->post(p("investigations/{$investigation->id}/move"), ['status' => 'findings_recorded'])->assertSessionHasErrors('status');
    $this->post(p("investigations/{$investigation->id}/move"), ['status' => 'investigating'])->assertSessionHasNoErrors();
    $this->post(p("investigations/{$investigation->id}/move"), ['status' => 'findings_recorded'])->assertSessionHasErrors('status');
    $this->post(p("investigations/{$investigation->id}/close"), ['closure_decision' => 'Nothing found, closing.'])->assertSessionHasErrors('closure_decision');

    $this->post(p("investigations/{$investigation->id}/notes"), ['kind' => 'finding', 'body' => 'Meter faulty.'])->assertSessionHasNoErrors();
    $this->post(p("investigations/{$investigation->id}/move"), ['status' => 'findings_recorded'])->assertSessionHasNoErrors();
    $this->post(p("investigations/{$investigation->id}/close"), ['closure_decision' => 'Meter replaced; readings back to normal.'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $investigation->fresh()->status))->toBe('closed');

    // Closed: nothing changes until it is reopened with a reason.
    $this->post(p("investigations/{$investigation->id}/notes"), ['kind' => 'observation', 'body' => 'late'])->assertSessionHasErrors('status');
    $this->post(p("investigations/{$investigation->id}/reopen"), [])->assertSessionHasErrors('reason');
    $this->post(p("investigations/{$investigation->id}/reopen"), ['reason' => 'The fault came back.'])->assertSessionHasNoErrors();
    $fresh = inTenant(fn () => $investigation->fresh());
    expect($fresh->status)->toBe('investigating')->and($fresh->closure_decision)->toBe('Meter replaced; readings back to normal.');

    $actions = inTenant(fn () => AuditEvent::query()->where('subject_id', $investigation->id)->orderBy('created_at')->pluck('action')->all());
    expect($actions)->toContain('investigation.opened', 'investigation.moved', 'investigation.noted', 'investigation.closed', 'investigation.reopened');
});

it('refuses a change made on a stale copy', function () {
    $investigation = openInvestigation();
    $this->put(p("investigations/{$investigation->id}"), ['title' => 'One', 'revision' => 1])->assertSessionHasNoErrors();
    $this->put(p("investigations/{$investigation->id}"), ['title' => 'Two', 'revision' => 1])->assertSessionHasErrors('stale');
    expect(inTenant(fn () => $investigation->fresh()->title))->toBe('One');
});

it('gives corrective tasks through the task rules and links them', function () {
    $investigation = openInvestigation();
    $this->post(p("investigations/{$investigation->id}/tasks"), ['title' => 'Replace the hour meter', 'assignee_id' => $this->team['other']->id])->assertSessionHasNoErrors();

    $task = inTenant(fn () => Task::query()->sole());
    expect($task->logbook_id)->toBe($this->logbook->id)->and($task->status)->toBe('pending');
    expect(inTenant(fn () => InvestigationEvidence::query()->sole()->only(['kind', 'role', 'subject_id'])))->toBe(['kind' => 'task', 'role' => 'corrective', 'subject_id' => $task->id]);
});

it('lets only investigators run it and auditors read it', function () {
    $investigation = openInvestigation();
    foreach (['writer' => 'logbook_contributor', 'reviewer' => 'logbook_reviewer', 'viewer' => 'logbook_viewer'] as $who => $role) {
        as_($this->team[$who], $role);
        $this->get(p("investigations/{$investigation->id}"))->assertNotFound();
        $this->get(p('investigations'))->assertForbidden();
        $this->post(p("investigations/{$investigation->id}/notes"), ['kind' => 'finding', 'body' => 'x'])->assertForbidden();
    }
    as_($this->team['auditor'], 'logbook_auditor');
    $this->get(p("investigations/{$investigation->id}"))->assertOk()->assertInertia(fn ($page) => $page->where('can.manage', false));
    $this->post(p("investigations/{$investigation->id}/notes"), ['kind' => 'finding', 'body' => 'x'])->assertForbidden();
});

it('never opens linked evidence the viewer could not open anyway', function () {
    // A restricted logbook with an entry only the manager and its member reach.
    $secret = logbook('Secret', ['restricted' => true]);
    $sheet = publishedSheet($secret, 'SEC', review: false);
    addMember($secret, $this->team['writer'], 'contributor');
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet)->assertSessionHasNoErrors();
    $hidden = latestEntry();

    as_($this->team['manager'], 'logbook_manager');
    $investigation = openInvestigation(['entry_id' => $this->entry->id]);
    $this->post(p("investigations/{$investigation->id}/evidence"), ['kind' => 'entry', 'subject_id' => $hidden->id, 'note' => 'secret note'])->assertSessionHasNoErrors();

    // An auditor reaches Equipment, not the restricted logbook (a fresh session: no flash from the manager).
    $this->flushSession();
    as_($this->team['auditor'], 'logbook_auditor');
    $this->get(p("investigations/{$investigation->id}"))->assertOk()->assertInertia(function ($page) use ($hidden) {
        $items = collect($page->toArray()['props']['evidence']);
        $locked = $items->firstWhere('restricted', true);
        expect($locked)->not->toBeNull()
            ->and($locked['label'])->toBeNull()->and($locked['subjectId'])->toBeNull()->and($locked['note'])->toBeNull()
            ->and(json_encode($page->toArray()['props']))->not->toContain($hidden->reference)->not->toContain('secret note');
    });
    $this->get(p("entries/{$hidden->id}"))->assertNotFound();
    // Nobody can link a record they cannot open.
    as_($this->team['manager'], 'logbook_manager');
    $other = (string) str()->uuid();
    $this->post(p("investigations/{$investigation->id}/evidence"), ['kind' => 'entry', 'subject_id' => $other])->assertSessionHasErrors('subject_id');
});

it('prints the findings and references, audited, without restricted evidence', function () {
    $investigation = openInvestigation(['entry_id' => $this->entry->id]);
    $this->post(p("investigations/{$investigation->id}/notes"), ['kind' => 'finding', 'body' => 'Meter faulty.'])->assertSessionHasNoErrors();
    $response = $this->get(p("investigations/{$investigation->id}/report.pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(strlen((string) $response->getContent()))->toBeGreaterThan(1000);
    expect(inTenant(fn () => AuditEvent::query()->where('action', 'investigation.printed')->count()))->toBe(1);
});

it('keeps investigations of one workspace out of another', function () {
    $investigation = openInvestigation();
    team('globex');
    as_(inTenant(fn () => User::query()->first(), 'globex'), 'logbook_manager', 'globex');
    $this->get(p("investigations/{$investigation->id}", 'globex'))->assertNotFound();
    $this->get(p('investigations', 'globex'))->assertOk()->assertInertia(fn ($page) => $page->has('investigations.data', 0));
});

it('changes nothing on the records it links', function () {
    $before = inTenant(fn () => Entry::query()->get()->map(fn ($e) => [$e->id, $e->status, $e->revision, $e->values, $e->updated_at->toIso8601String()])->all());
    $investigation = openInvestigation(['entry_id' => $this->entry->id]);
    $exception = inTenant(fn () => LogException::query()->sole());
    $this->post(p("investigations/{$investigation->id}/evidence"), ['kind' => 'exception', 'subject_id' => $exception->id])->assertSessionHasNoErrors();

    expect(inTenant(fn () => Entry::query()->get()->map(fn ($e) => [$e->id, $e->status, $e->revision, $e->values, $e->updated_at->toIso8601String()])->all()))->toBe($before)
        ->and(inTenant(fn () => $exception->fresh()->status))->toBe('open');
});
