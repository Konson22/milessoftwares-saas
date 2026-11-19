<?php

use App\Logbook\Models\Attachment;
use App\Logbook\Models\EntryRevision;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Evidence: private files under the organization, served only to people
 * who can read the entry, and kept when part of a submitted revision.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook();
    $fields = generatorFields();
    $fields[12]['required'] = true;
    $this->sheet = publishedSheet($this->logbook, fields: $fields);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($this->sheet, submit: false)->assertSessionHasNoErrors();
    $this->entry = latestEntry();
});

function attach(string $entryId, UploadedFile $file, string $field = 'photo')
{
    return test()->post(p("entries/{$entryId}/attachments"), ['field' => $field, 'file' => $file]);
}

it('stores files privately, under the organization, with a generated name', function () {
    attach($this->entry->id, UploadedFile::fake()->image('../../etc/passwd.jpg'))->assertSessionHasNoErrors();
    $file = inTenant(fn () => Attachment::query()->sole());

    expect($file->path)->toStartWith("logbook/{$this->entry->organization_id}/{$this->entry->id}/")
        ->and($file->path)->not->toContain('passwd')
        ->and($file->name)->not->toContain('/')
        ->and($this->entry->fresh()->values['photo'])->toBe([$file->id]);
    Storage::disk('local')->assertExists($file->path);

    $this->get(p("attachments/{$file->id}"))->assertOk()->assertDownload()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('requires a required file before submitting', function () {
    $this->post(p("entries/{$this->entry->id}/submit"))->assertSessionHasErrors('values.photo');
    attach($this->entry->id, UploadedFile::fake()->image('meter.jpg'))->assertSessionHasNoErrors();
    $this->post(p("entries/{$this->entry->id}/submit"))->assertSessionHasNoErrors();
    expect($this->entry->fresh()->status)->toBe('submitted');
});

it('refuses other file types, large files and unknown fields', function () {
    attach($this->entry->id, UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload'))->assertSessionHasErrors('file');
    attach($this->entry->id, UploadedFile::fake()->create('page.html', 10, 'text/html'))->assertSessionHasErrors('file');
    attach($this->entry->id, UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'))->assertSessionHasErrors('file');
    attach($this->entry->id, UploadedFile::fake()->image('a.jpg'), 'generator')->assertSessionHasErrors('field');
    attach($this->entry->id, UploadedFile::fake()->image('a.jpg'), 'nope')->assertSessionHasErrors('field');

    expect(inTenant(fn () => Attachment::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('deletes files removed from a draft, and keeps those already submitted', function () {
    attach($this->entry->id, UploadedFile::fake()->image('first.jpg'))->assertSessionHasNoErrors();
    $first = inTenant(fn () => Attachment::query()->sole());
    $this->delete(p("attachments/{$first->id}"))->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($first->path);

    attach($this->entry->id, UploadedFile::fake()->image('meter.jpg'))->assertSessionHasNoErrors();
    $this->post(p("entries/{$this->entry->id}/submit"))->assertSessionHasNoErrors();
    $submitted = inTenant(fn () => Attachment::query()->sole());

    // A submitted entry's files cannot be touched.
    $this->delete(p("attachments/{$submitted->id}"))->assertSessionHasErrors('entry');

    // Returned for correction: the author swaps the photo; the old one stays for revision 1.
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->post(p("entries/{$this->entry->id}/review"), ['decision' => 'returned', 'comment' => 'Blurred photo'])->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    attach($this->entry->id, UploadedFile::fake()->image('sharp.jpg'))->assertSessionHasNoErrors();
    $this->delete(p("attachments/{$submitted->id}"))->assertSessionHasNoErrors();
    $this->post(p("entries/{$this->entry->id}/submit"))->assertSessionHasNoErrors();

    Storage::disk('local')->assertExists($submitted->path);
    expect(inTenant(fn () => $submitted->fresh()->removed_at))->not->toBeNull();
    $revisions = inTenant(fn () => EntryRevision::query()->orderBy('revision')->get());
    expect($revisions[0]->values['photo'])->toBe([$submitted->id])
        ->and($revisions[1]->values['photo'])->not->toContain($submitted->id);

    // Reviewers still open the earlier evidence.
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->get(p("attachments/{$submitted->id}"))->assertOk();
});

it('serves files only to people who can read the entry', function () {
    attach($this->entry->id, UploadedFile::fake()->image('meter.jpg'))->assertSessionHasNoErrors();
    $file = inTenant(fn () => Attachment::query()->sole());

    // A draft's files: its author only.
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->get(p("attachments/{$file->id}"))->assertNotFound();

    as_($this->team['writer'], 'logbook_contributor');
    $this->post(p("entries/{$this->entry->id}/submit"))->assertSessionHasNoErrors();
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->get(p("attachments/{$file->id}"))->assertOk();

    // Restricting the logbook takes the files away from those not listed.
    as_($this->team['manager'], 'logbook_manager');
    $this->put(p("logbooks/{$this->logbook->id}"), ['name' => 'Facility Management', 'restricted' => true])->assertSessionHasNoErrors();
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->get(p("attachments/{$file->id}"))->assertNotFound();

    // Only the author attaches or removes.
    as_($this->team['other'], 'logbook_contributor');
    $this->delete(p("attachments/{$file->id}"))->assertNotFound();
});
