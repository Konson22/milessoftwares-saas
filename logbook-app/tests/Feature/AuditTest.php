<?php

use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\EntryReview;
use App\Logbook\Models\EntryRevision;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The audit trail: every important action, append-only, readable by
 * auditors only, never holding entry values.
 */

beforeEach(function () {
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook();
    $this->sheet = publishedSheet($this->logbook);
    $this->post(p("logbooks/{$this->logbook->id}/access"), ['user_id' => $this->team['viewer']->id, 'role' => 'viewer']);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($this->sheet, generatorValues(['notes' => 'secret meter reading 1234']))->assertSessionHasNoErrors();
    $this->entry = latestEntry();
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->post(p("entries/{$this->entry->id}/review"), ['decision' => 'returned', 'comment' => 'Fix it']);
    as_($this->team['writer'], 'logbook_contributor');
    $this->post(p("entries/{$this->entry->id}/submit"))->assertSessionHasNoErrors();
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->post(p("entries/{$this->entry->id}/review"), ['decision' => 'reviewed'])->assertSessionHasNoErrors();
});

it('records every important action with who did it', function () {
    $events = inTenant(fn () => AuditEvent::query()->orderBy('created_at')->get());

    expect($events->pluck('action')->all())->toBe([
        'logbook.created', 'sheet.created', 'template.published', 'logbook.access_granted',
        'entry.created', 'entry.submitted', 'entry.returned', 'entry.resubmitted', 'entry.reviewed',
    ])
        ->and($events->firstWhere('action', 'entry.reviewed')->user_name)->toBe('Reviewer')
        ->and($events->firstWhere('action', 'logbook.access_granted')->details)->toMatchArray(['member' => 'Viewer', 'role' => 'viewer'])
        ->and(json_encode($events->pluck('details')))->not->toContain('secret');
});

it('shows the trail to auditors only, with no way to change it', function () {
    as_($this->team['auditor'], 'logbook_auditor');
    $this->get(p('audit'))->assertOk()->assertInertia(fn ($page) => $page->where('events.total', 9));
    $this->get(p('audit?type=entry'))->assertInertia(fn ($page) => $page->where('events.total', 5));

    foreach (['manager' => 'logbook_manager', 'reviewer' => 'logbook_reviewer', 'writer' => 'logbook_contributor'] as $who => $role) {
        as_($this->team[$who], $role);
        $this->get(p('audit'))->assertForbidden();
    }

    as_($this->team['auditor'], 'logbook_auditor');
    $event = inTenant(fn () => AuditEvent::query()->first());
    $this->delete(p("audit/{$event->id}"))->assertStatus(404);
    $this->put(p("audit/{$event->id}"))->assertStatus(404);
});

it('shows each entry\'s own history', function () {
    as_($this->team['viewer'], 'logbook_viewer');
    $this->get(p("entries/{$this->entry->id}"))->assertInertia(fn ($page) => $page
        ->where('history', fn ($history) => collect($history)->pluck('action')->all() === ['entry.created', 'entry.submitted', 'entry.returned', 'entry.resubmitted', 'entry.reviewed'])
        ->has('reviews', 2)->has('revisions', 2));
});

it('refuses changes to the trail, revisions and reviews in the database', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The append-only triggers are PostgreSQL only.');
    }

    foreach ([AuditEvent::class, EntryRevision::class, EntryReview::class] as $model) {
        // Each attempt in its own savepoint: a refused statement aborts the transaction around it.
        expect(fn () => DB::transaction(fn () => inTenant(fn () => $model::query()->limit(1)->delete())))->toThrow(QueryException::class, 'append-only');
        expect(fn () => DB::transaction(fn () => inTenant(fn () => $model::query()->limit(1)->update(['organization_id' => $this->entry->organization_id]))))->toThrow(QueryException::class, 'append-only');
    }
});
