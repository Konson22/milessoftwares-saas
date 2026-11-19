<?php

use App\Logbook\Models\Attachment;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Logbook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;

/*
 * Multi-tenancy: nothing of one organization is reachable from another,
 * whatever id is put in the URL or the form. Answers are 404, so ids
 * reveal nothing.
 */

beforeEach(function () {
    Storage::fake('local');

    // Globex keeps a logbook with a sheet, a submitted entry and a photo.
    $globex = team('globex');
    as_($globex['manager'], 'logbook_manager', 'globex');
    $this->theirLogbook = logbook('Globex Fleet', slug: 'globex');
    $this->theirSheet = publishedSheet($this->theirLogbook, 'GLX', slug: 'globex');
    as_($globex['writer'], 'logbook_contributor', 'globex');
    writeEntry($this->theirSheet, submit: false, slug: 'globex')->assertSessionHasNoErrors();
    $this->theirEntry = latestEntry('globex');
    $this->post(p("entries/{$this->theirEntry->id}/attachments", 'globex'), ['field' => 'photo', 'file' => UploadedFile::fake()->image('meter.jpg')])->assertSessionHasNoErrors();
    $this->post(p("entries/{$this->theirEntry->id}/submit", 'globex'))->assertSessionHasNoErrors();
    $this->theirAttachment = inTenant(fn () => Attachment::query()->sole(), 'globex');
    $this->theirWriter = $globex['writer'];

    // Acme's administrator: everything in Acme, nothing elsewhere.
    $this->admin = actingAsRole('logbook_administrator', name: 'Acme admin');
    $this->ourLogbook = logbook('Acme Ops');
    $this->ourSheet = publishedSheet($this->ourLogbook, 'ACM');
});

it('answers 404 for another organization\'s records', function () {
    $this->get(p("logbooks/{$this->theirLogbook->id}"))->assertNotFound();
    $this->get(p("sheets/{$this->theirSheet->id}"))->assertNotFound();
    $this->get(p("sheets/{$this->theirSheet->id}/template"))->assertNotFound();
    $this->get(p("entries/{$this->theirEntry->id}"))->assertNotFound();
    $this->get(p("entries/{$this->theirEntry->id}/pdf"))->assertNotFound();
    $this->get(p("attachments/{$this->theirAttachment->id}"))->assertNotFound();
    $this->get(p('entries/new?sheet='.$this->theirSheet->id))->assertNotFound();
});

it('refuses to act on another organization\'s records', function () {
    $this->put(p("logbooks/{$this->theirLogbook->id}"), ['name' => 'Taken'])->assertNotFound();
    $this->post(p("logbooks/{$this->theirLogbook->id}/archive"))->assertNotFound();
    $this->post(p("logbooks/{$this->theirLogbook->id}/sheets"), ['name' => 'X', 'code' => 'XQ'])->assertNotFound();
    $this->post(p("logbooks/{$this->theirLogbook->id}/access"), ['user_id' => $this->admin->id, 'role' => 'viewer'])->assertNotFound();
    $this->post(p("sheets/{$this->theirSheet->id}/publish"))->assertNotFound();
    $this->post(p("sheets/{$this->theirSheet->id}/archive"))->assertNotFound();
    $this->post(p("entries/{$this->theirEntry->id}/review"), ['decision' => 'reviewed'])->assertNotFound();
    $this->post(p("entries/{$this->theirEntry->id}/submit"))->assertNotFound();
    $this->delete(p("attachments/{$this->theirAttachment->id}"))->assertNotFound();
    $this->post(p("entries/{$this->theirEntry->id}/attachments"), ['field' => 'photo', 'file' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();

    inTenant(function () {
        expect(Logbook::query()->sole()->only(['name', 'status']))->toBe(['name' => 'Globex Fleet', 'status' => 'active'])
            ->and(Entry::query()->sole()->status)->toBe('submitted')
            ->and(Attachment::query()->sole()->removed_at)->toBeNull();
    }, 'globex');
});

it('refuses another organization\'s ids inside its own forms', function () {
    writeEntry($this->theirSheet)->assertNotFound();
    $this->post(p("sheets/{$this->ourSheet->id}/duplicate"), ['name' => 'Copy', 'code' => 'CPY', 'logbook_id' => $this->theirLogbook->id])->assertNotFound();
    $this->post(p("logbooks/{$this->ourLogbook->id}/access"), ['user_id' => $this->admin->id, 'role' => 'viewer', 'log_sheet_id' => $this->theirSheet->id])->assertNotFound();
    // A person field naming someone who is not a member here.
    writeEntry($this->ourSheet, generatorValues(['operator' => $this->theirWriter->id]))->assertSessionHasErrors('values.operator');
});

it('lists, counts, reports and exports only the organization\'s own entries', function () {
    writeEntry($this->ourSheet)->assertSessionHasNoErrors();

    $this->get(p('entries?scope=all'))->assertInertia(fn ($page) => $page->where('entries.total', 1));
    $this->get(p('logbooks'))->assertInertia(fn ($page) => $page->has('logbooks', 1));
    $this->get(p())->assertInertia(fn ($page) => $page->where('figures.entries', 1)->where('figures.logbooks', 1));
    $this->get(p('reports'))->assertInertia(fn ($page) => $page->where('counts.total', 1));
    $this->get(p('reports?sheet='.$this->theirSheet->id))->assertInertia(fn ($page) => $page->where('counts.total', 0)->where('numbers', null));
    $this->get(p('reports/entries.csv?sheet='.$this->theirSheet->id))->assertNotFound();
    $csv = $this->get(p('reports/entries.csv'))->assertOk()->streamedContent();
    expect($csv)->toContain('ACM-')->not->toContain('GLX-');
    $this->get(p('audit'))->assertInertia(fn ($page) => $page->where('events.data', fn ($events) => collect($events)->every(fn ($e) => ! str_contains($e['subject'], 'Globex') && ! str_contains($e['subject'], 'GLX'))));
});

it('keeps each organization separate for a person who belongs to both', function () {
    // The Globex writer is also an Acme contributor: switching workspaces switches everything.
    actingAsRole('logbook_contributor', $this->theirWriter, 'acme');
    $this->get(p('entries'))->assertInertia(fn ($page) => $page->where('entries.total', 0));
    writeEntry($this->ourSheet)->assertSessionHasNoErrors();

    as_($this->theirWriter, 'logbook_contributor', 'globex');
    $this->get(p('entries', 'globex'))->assertInertia(fn ($page) => $page->where('entries.total', 1)->where('entries.data.0.sheet.code', 'GLX'));
    $this->get(p('entries/'.latestEntry()->id, 'globex'))->assertNotFound();

    // The session belongs to one workspace: the other host does not reuse it.
    as_($this->theirWriter, 'logbook_contributor', 'acme');
    $this->get(p('entries', 'acme'))->assertInertia(fn ($page) => $page->where('entries.total', 1)->where('entries.data.0.sheet.code', 'ACM'));
});

it('refuses a workspace that is not entitled to Logbook', function () {
    $user = $this->platformUser();
    $org = $this->platform()->organization('acme');
    $this->platform()->grant('acme', $user->getAuthIdentifier(), allowed: false);

    $this->actingAs($user)
        ->withSession([RequirePlatformAccess::SESSION_TENANT => $org['id']])
        ->get(p('entries'))
        ->assertForbidden();
});
