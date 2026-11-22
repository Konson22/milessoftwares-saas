<?php

use App\Logbook\Models\AccessGrant;
use App\Logbook\Models\LogSheet;
use Illuminate\Support\Facades\Cache;

/*
 * Two layers (ADR-0025 §2.5): the catalog says what a member may do, a
 * restricted logbook's access list says where. Neither grants more than
 * the other allows.
 */

beforeEach(function () {
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->open = logbook('Office Administration');
    $this->openSheet = publishedSheet($this->open, 'OFF');
    $this->secure = logbook('Security Operations', ['restricted' => true]);
    $this->guard = publishedSheet($this->secure, 'SEC');
    $this->patrol = publishedSheet($this->secure, 'PAT');
});

function grant(string $logbookId, string $userId, string $role, ?string $sheetId = null)
{
    return test()->post(p("logbooks/{$logbookId}/access"), ['user_id' => $userId, 'role' => $role, 'log_sheet_id' => $sheetId]);
}

it('keeps a restricted logbook from members not listed on it', function () {
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('logbooks'))->assertInertia(fn ($page) => $page->has('logbooks', 1)->where('logbooks.0.name', 'Office Administration'));
    $this->get(p("logbooks/{$this->secure->id}"))->assertNotFound();
    $this->get(p("sheets/{$this->guard->id}"))->assertNotFound();
    writeEntry($this->guard)->assertNotFound();
    writeEntry($this->openSheet)->assertSessionHasNoErrors();
});

it('lets listed contributors write, on the whole logbook or one sheet', function () {
    grant($this->secure->id, $this->team['writer']->id, 'contributor')->assertSessionHasNoErrors();
    grant($this->secure->id, $this->team['other']->id, 'contributor', $this->guard->id)->assertSessionHasNoErrors();

    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($this->guard)->assertSessionHasNoErrors();
    writeEntry($this->patrol)->assertSessionHasNoErrors();

    as_($this->team['other'], 'logbook_contributor');
    writeEntry($this->guard)->assertSessionHasNoErrors();
    writeEntry($this->patrol)->assertNotFound();
    $this->get(p("logbooks/{$this->secure->id}"))->assertInertia(fn ($page) => $page->has('sheets', 1)->where('sheets.0.code', 'SEC'));
    // Contributors read their own entries only, never colleagues'.
    $this->get(p('entries?scope=all'))->assertInertia(fn ($page) => $page->where('entries.total', 1));
});

it('never grants more than the member\'s catalog permissions', function () {
    // Added as contributor, but the viewer role has no write permission: refused, with the reason.
    grant($this->secure->id, $this->team['viewer']->id, 'contributor')->assertSessionHasErrors('role');
    as_($this->team['viewer'], 'logbook_viewer');
    $this->get(p("logbooks/{$this->secure->id}"))->assertNotFound();

    // Added as viewer, with the view permission: reads, still cannot write.
    as_($this->team['manager'], 'logbook_manager');
    grant($this->secure->id, $this->team['viewer']->id, 'viewer')->assertSessionHasNoErrors();
    as_($this->team['viewer'], 'logbook_viewer');
    $this->get(p("logbooks/{$this->secure->id}"))->assertOk();
    writeEntry($this->guard)->assertForbidden();
});

it('lets reviewers review only where they are listed', function () {
    grant($this->secure->id, $this->team['writer']->id, 'contributor')->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($this->guard)->assertSessionHasNoErrors();
    $entry = latestEntry();

    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->get(p('reviews'))->assertInertia(fn ($page) => $page->has('pending', 0));
    $this->post(p("entries/{$entry->id}/review"), ['decision' => 'reviewed'])->assertNotFound();

    as_($this->team['manager'], 'logbook_manager');
    grant($this->secure->id, $this->team['reviewer']->id, 'reviewer', $this->guard->id)->assertSessionHasNoErrors();
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->get(p('reviews'))->assertInertia(fn ($page) => $page->has('pending', 1));
    $this->post(p("entries/{$entry->id}/review"), ['decision' => 'reviewed'])->assertSessionHasNoErrors();
});

it('takes access away when the listing is removed', function () {
    grant($this->secure->id, $this->team['writer']->id, 'contributor')->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($this->guard, submit: false)->assertSessionHasNoErrors();
    $draft = latestEntry();

    as_($this->team['manager'], 'logbook_manager');
    $grant = inTenant(fn () => AccessGrant::query()->where('logbook_id', $this->secure->id)->sole());
    $this->delete(p("access/{$grant->id}"))->assertSessionHasNoErrors();

    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($this->guard)->assertNotFound();
    // Their own draft stays theirs, but it can no longer be submitted there.
    $this->post(p("entries/{$draft->id}/submit"))->assertForbidden();
});

it('only lists members of the organization, and sheets of the same logbook', function () {
    $stranger = $this->platformUser(['name' => 'Stranger']);
    grant($this->secure->id, $stranger->id, 'contributor')->assertSessionHasErrors('user_id');
    grant($this->secure->id, $this->team['writer']->id, 'owner')->assertSessionHasErrors('role');
    grant($this->secure->id, $this->team['writer']->id, 'contributor', $this->openSheet->id)->assertSessionHasErrors('log_sheet_id');
});

it('lets managers and owners reach every logbook', function () {
    $this->get(p("logbooks/{$this->secure->id}"))->assertOk()->assertInertia(fn ($page) => $page->has('sheets', 2));

    $owner = $this->platformUser(['name' => 'Owner']);
    $this->actingAsMember('acme', [], owner: true, user: $owner);
    Cache::flush();
    $this->get(p("logbooks/{$this->secure->id}"))->assertOk();
    writeEntry($this->guard)->assertSessionHasNoErrors();
});

it('opens a restricted logbook to everyone when it is made open', function () {
    $this->put(p("logbooks/{$this->secure->id}"), ['name' => 'Security Operations', 'restricted' => false])->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry(inTenant(fn () => LogSheet::query()->where('code', 'PAT')->sole()))->assertSessionHasNoErrors();
});
