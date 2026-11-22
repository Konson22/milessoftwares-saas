<?php

use App\Logbook\Models\EntryReview;
use App\Logbook\Models\EntryRevision;
use Illuminate\Support\Facades\Cache;

/*
 * Reviews (ADR-0025 §2.6): submitted → reviewed, or returned → corrected
 * → resubmitted → reviewed. Every submission is kept; nobody reviews their
 * own entry; sheets without review finalize without pretending a review.
 */

beforeEach(function () {
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook();
    $this->sheet = publishedSheet($this->logbook);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($this->sheet)->assertSessionHasNoErrors();
    $this->entry = latestEntry();
});

function review(string $entryId, string $decision, ?string $comment = null)
{
    return test()->post(p("entries/{$entryId}/review"), ['decision' => $decision, 'comment' => $comment]);
}

it('shows submitted entries to reviewers and records the review', function () {
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->get(p('reviews'))->assertInertia(fn ($page) => $page->has('pending', 1)->where('pending.0.reference', $this->entry->reference));
    $this->get(p("entries/{$this->entry->id}"))->assertInertia(fn ($page) => $page->where('can.review', true));

    review($this->entry->id, 'reviewed', 'Checked against the meter.')->assertSessionHasNoErrors();

    $entry = $this->entry->fresh();
    expect($entry->status)->toBe('reviewed')
        ->and($entry->reviewed_by)->toBe($this->team['reviewer']->id)
        ->and(inTenant(fn () => EntryReview::query()->sole()->only(['decision', 'revision', 'comment'])))
        ->toBe(['decision' => 'reviewed', 'revision' => 1, 'comment' => 'Checked against the meter.']);
    $this->get(p('reviews'))->assertInertia(fn ($page) => $page->has('pending', 0)->has('decided', 1));
});

it('returns an entry with a comment, keeps what was submitted, and takes the correction', function () {
    as_($this->team['reviewer'], 'logbook_reviewer');
    review($this->entry->id, 'returned')->assertSessionHasErrors('comment');
    review($this->entry->id, 'returned', 'Hours look wrong: the meter says 4.')->assertSessionHasNoErrors();
    expect($this->entry->fresh()->status)->toBe('returned');

    // The author corrects and resubmits.
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p("entries/{$this->entry->id}/edit"))->assertInertia(fn ($page) => $page
        ->where('entry.returnComment', 'Hours look wrong: the meter says 4.')->where('entry.returnedBy', 'Reviewer'));
    $this->put(p("entries/{$this->entry->id}"), ['occurred_at' => '2026-11-19T08:30', 'values' => generatorValues(['hours' => '4']), 'submit' => true])
        ->assertSessionHasNoErrors();

    $entry = $this->entry->fresh();
    expect($entry->status)->toBe('submitted')->and($entry->revision)->toBe(2)
        ->and($entry->reference)->toBe($this->entry->reference);
    $revisions = inTenant(fn () => EntryRevision::query()->where('log_entry_id', $entry->id)->orderBy('revision')->get());
    expect($revisions->pluck('values.hours')->all())->toBe(['6.5', '4']);

    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->get(p("entries/{$entry->id}"))->assertInertia(fn ($page) => $page
        ->has('revisions', 2)->where('revisions.0.revision', 2)->where('revisions.0.changed', ['hours'])
        ->has('reviews', 1));
    review($entry->id, 'reviewed')->assertSessionHasNoErrors();
    expect($entry->fresh()->status)->toBe('reviewed');
});

it('never lets anyone review their own entry', function () {
    // The writer also holds the reviewer role: still refused.
    test()->actingAsMember('acme', [...rolePermissions('logbook_contributor'), ...rolePermissions('logbook_reviewer')], user: $this->team['writer']);
    Cache::flush();

    $this->get(p('reviews'))->assertInertia(fn ($page) => $page->has('pending', 0));
    review($this->entry->id, 'reviewed')->assertSessionHasErrors('decision');

    // Not even the workspace owner.
    $this->actingAsMember('acme', [], owner: true, user: $this->team['writer']);
    Cache::flush();
    review($this->entry->id, 'reviewed')->assertSessionHasErrors('decision');
    expect($this->entry->fresh()->status)->toBe('submitted');
});

it('needs the reviewer permission', function () {
    foreach (['other' => 'logbook_contributor', 'viewer' => 'logbook_viewer', 'manager' => 'logbook_manager'] as $who => $role) {
        as_($this->team[$who], $role);
        review($this->entry->id, 'reviewed')->assertForbidden();
        $this->get(p('reviews'))->assertForbidden();
    }
    expect($this->entry->fresh()->status)->toBe('submitted');
});

it('refuses transitions out of order', function () {
    as_($this->team['reviewer'], 'logbook_reviewer');
    review($this->entry->id, 'reviewed')->assertSessionHasNoErrors();
    review($this->entry->id, 'returned', 'Too late')->assertSessionHasErrors('decision');
    review($this->entry->id, 'approved')->assertSessionHasErrors('decision');

    // A reviewed entry never changes, not even by its author.
    as_($this->team['writer'], 'logbook_contributor');
    $this->put(p("entries/{$this->entry->id}"), ['occurred_at' => '2026-11-19T08:30', 'values' => generatorValues(['hours' => '1'])])->assertSessionHasErrors('entry');
    $this->post(p("entries/{$this->entry->id}/submit"))->assertSessionHasErrors('entry');
    $this->get(p("entries/{$this->entry->id}/edit"))->assertStatus(409);

    // A draft is not reviewable (and not even visible to the reviewer).
    writeEntry($this->sheet, submit: false);
    $draft = latestEntry();
    as_($this->team['reviewer'], 'logbook_reviewer');
    review($draft->id, 'reviewed')->assertNotFound();

    // Someone else cannot change the author's returned entry, nor even see it.
    as_($this->team['other'], 'logbook_contributor');
    $this->put(p("entries/{$this->entry->id}"), ['occurred_at' => '2026-11-19T08:30', 'values' => generatorValues()])->assertNotFound();
});

it('finalizes entries on sheets without review, never marking them reviewed', function () {
    as_($this->team['manager'], 'logbook_manager');
    $sheet = publishedSheet($this->logbook, 'ITM', review: false);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet)->assertSessionHasNoErrors();
    $entry = latestEntry();

    expect($entry->status)->toBe('finalized')->and($entry->review_required)->toBeFalse()
        ->and($entry->reviewed_at)->toBeNull()->and($entry->reviewed_by)->toBeNull();

    as_($this->team['reviewer'], 'logbook_reviewer');
    review($entry->id, 'reviewed')->assertSessionHasErrors('decision');
    $this->get(p('reviews'))->assertInertia(fn ($page) => $page->has('pending', 1));
});

it('fixes the review requirement at submission', function () {
    as_($this->team['manager'], 'logbook_manager');
    $this->put(p("sheets/{$this->sheet->id}"), ['name' => 'GEN log', 'code' => 'GEN', 'review_required' => false])->assertSessionHasNoErrors();

    expect($this->entry->fresh()->status)->toBe('submitted');
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($this->sheet)->assertSessionHasNoErrors();
    expect(latestEntry()->status)->toBe('finalized');
});
