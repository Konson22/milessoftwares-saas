<?php

use App\Modules\Documents\Models\Document;
use App\Modules\Employees\Models\AuditEntry;
use App\Modules\Employees\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/** A real file on disk, so its type is read from what it contains. */
function upload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'hrdoc');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function pdf(string $name = 'Contract Akol.pdf'): UploadedFile
{
    return upload($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

const OFFICER = ['hr.employees.view', 'hr.employees.manage', 'hr.employment.manage', 'hr.documents.view', 'hr.documents.manage'];
const MANAGER = [...OFFICER, 'hr.personal.view', 'hr.personal.manage'];

beforeEach(function () {
    Storage::fake(Document::DISK);
    $this->juba = $this->platform()->branch('acme', 'Juba', 'JUB');
    $this->wau = $this->platform()->branch('acme', 'Wau', 'WAU');

    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage']);
    $this->post($this->workspaceUrl('acme', 'employees'), [
        'first_name' => 'Akol', 'last_name' => 'Deng', 'branch_id' => $this->juba, 'hired_on' => '2026-01-05', 'contract_type' => 'permanent',
    ])->assertSessionHasNoErrors();
    $this->employee = $this->asTenant('acme', fn () => Employee::query()->sole());
    $this->documentsUrl = $this->workspaceUrl('acme', "employees/{$this->employee->id}/documents");
});

it('keeps the file under its own path and serves it only through a short-lived signed link', function () {
    $this->actingAsMember('acme', OFFICER);
    $this->post($this->documentsUrl, [
        'file' => pdf('../../etc/Contract "Akol".pdf'), 'category' => 'contract', 'title' => 'Employment contract', 'issued_on' => '2026-01-05',
    ])->assertSessionHasNoErrors();

    $document = $this->asTenant('acme', fn () => Document::query()->sole());
    expect($document->path)->toBe("{$document->organization_id}/{$this->employee->id}/{$document->id}")
        ->and($document->original_name)->toBe('Contract Akol.pdf')
        ->and($document->mime_type)->toBe('application/pdf')
        ->and($document->sha256)->toHaveLength(64);
    Storage::disk(Document::DISK)->assertExists($document->path);

    $this->get($this->documentsUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('employees/documents')
        ->has('documents', 1)
        ->where('documents.0.title', 'Employment contract')
        ->where('documents.0.canRemove', true)
        ->where('hidden', 0));

    // Without a signature, or once the signature has expired, there is no file.
    $download = $this->workspaceUrl('acme', "documents/{$document->id}/download");
    $this->get($download)->assertForbidden();

    $signed = $this->get($this->workspaceUrl('acme', "documents/{$document->id}/open"))->assertRedirect()->headers->get('Location');
    expect($signed)->toContain('signature=');

    $response = $this->get($signed)->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('Contract Akol.pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $this->travel(6)->minutes();
    $this->get($signed)->assertForbidden();

    // A row whose file was lost (a partial restore) is not found, and not logged as a download.
    Storage::disk(Document::DISK)->delete($document->path);
    $this->get($this->get($this->workspaceUrl('acme', "documents/{$document->id}/open"))->headers->get('Location'))->assertNotFound();

    $this->asTenant('acme', function () use ($document) {
        expect(AuditEntry::query()->orderBy('created_at')->orderBy('id')->get(['action', 'subject_id'])->toArray())->toBe([
            ['action' => 'documents.uploaded', 'subject_id' => $document->id],
            ['action' => 'documents.downloaded', 'subject_id' => $document->id],
        ]);
    });
});

it('accepts PDF, JPEG and PNG judged by their content, up to the size limit', function () {
    $this->actingAsMember('acme', OFFICER);
    $base = ['category' => 'certificate', 'title' => 'Diploma'];

    // A web page renamed .pdf is still a web page.
    $this->post($this->documentsUrl, [...$base, 'file' => upload('diploma.pdf', '<html><script>alert(1)</script></html>')])->assertSessionHasErrors('file');
    // A real PDF called something else is refused too.
    $this->post($this->documentsUrl, [...$base, 'file' => pdf('diploma.exe')])->assertSessionHasErrors('file');
    $this->post($this->documentsUrl, [...$base, 'file' => UploadedFile::fake()->create('big.pdf', 9 * 1024, 'application/pdf')])->assertSessionHasErrors('file');
    $this->post($this->documentsUrl, [...$base, 'file' => pdf(), 'issued_on' => '2026-05-01', 'expires_on' => '2026-04-01'])->assertSessionHasErrors('expires_on');

    $this->post($this->documentsUrl, [...$base, 'file' => UploadedFile::fake()->image('diploma.png')])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => Document::query()->count()))->toBe(1)
        ->and(Storage::disk(Document::DISK)->allFiles())->toHaveCount(1);
});

it('follows the staff: documents of someone out of reach are not found', function () {
    $this->actingAsMember('acme', OFFICER);
    $this->post($this->documentsUrl, ['file' => pdf(), 'category' => 'contract', 'title' => 'Contract'])->assertSessionHasNoErrors();
    $document = $this->asTenant('acme', fn () => Document::query()->sole());

    // An officer of another branch.
    $this->actingAsMember('acme', OFFICER, branch: $this->wau);
    $this->get($this->documentsUrl)->assertNotFound();
    $this->get($this->workspaceUrl('acme', "documents/{$document->id}/open"))->assertNotFound();
    $this->delete($this->workspaceUrl('acme', "documents/{$document->id}"))->assertNotFound();

    // A line manager sees the person, not their documents.
    $this->actingAsMember('acme', ['hr.employees.view'], branch: $this->juba);
    $this->get($this->documentsUrl)->assertForbidden();
    $this->get($this->workspaceUrl('acme', "employees/{$this->employee->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('can.documents', false));

    // Viewing documents is not managing them.
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.documents.view'], branch: $this->juba);
    $this->get($this->documentsUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canUpload', false)->where('documents.0.canRemove', false));
    $this->post($this->documentsUrl, ['file' => pdf(), 'category' => 'contract', 'title' => 'Another'])->assertForbidden();
    $this->delete($this->workspaceUrl('acme', "documents/{$document->id}"))->assertForbidden();

    // Another workspace never reaches it.
    $this->actingAsMember('globex', MANAGER);
    $this->get($this->workspaceUrl('globex', "documents/{$document->id}/open"))->assertNotFound();
});

it('keeps identity documents to holders of the personal permissions', function () {
    $this->actingAsMember('acme', OFFICER);
    $this->post($this->documentsUrl, ['file' => pdf('passport.pdf'), 'category' => 'identity', 'title' => 'Passport'])->assertForbidden();
    $this->get($this->documentsUrl)->assertInertia(fn (Assert $page) => $page
        ->where('categories', fn ($categories) => collect($categories)->doesntContain('value', 'identity')));

    $this->actingAsMember('acme', MANAGER);
    $this->post($this->documentsUrl, ['file' => pdf('passport.pdf'), 'category' => 'identity', 'title' => 'Passport', 'expires_on' => '2030-01-01'])->assertSessionHasNoErrors();
    $document = $this->asTenant('acme', fn () => Document::query()->sole());

    $this->actingAsMember('acme', OFFICER);
    $this->get($this->documentsUrl)->assertInertia(fn (Assert $page) => $page->has('documents', 0)->where('hidden', 1));
    $this->get($this->workspaceUrl('acme', "documents/{$document->id}/open"))->assertNotFound();
    $this->delete($this->workspaceUrl('acme', "documents/{$document->id}"))->assertNotFound();

    $this->actingAsMember('acme', MANAGER);
    $this->get($this->workspaceUrl('acme', "documents/{$document->id}/open"))->assertRedirect();
});

it('removes the file and keeps a record of who removed it', function () {
    $this->actingAsMember('acme', OFFICER);
    $this->post($this->documentsUrl, ['file' => pdf(), 'category' => 'letter', 'title' => 'Offer letter'])->assertSessionHasNoErrors();
    $document = $this->asTenant('acme', fn () => Document::query()->sole());

    $this->delete($this->workspaceUrl('acme', "documents/{$document->id}"))->assertSessionHasNoErrors();

    Storage::disk(Document::DISK)->assertMissing($document->path);
    $this->get($this->workspaceUrl('acme', "documents/{$document->id}/open"))->assertNotFound();
    $this->get($this->documentsUrl)->assertInertia(fn (Assert $page) => $page
        ->has('documents', 1)
        ->whereNot('documents.0.removedAt', null)
        ->where('documents.0.canRemove', false));
    expect($this->asTenant('acme', fn () => AuditEntry::query()->where('action', 'documents.removed')->value('subject_id')))->toBe($document->id);
});

it('lists expired and soon-expiring documents of current staff on the home page', function () {
    $this->travelTo('2026-10-19');
    $this->actingAsMember('acme', MANAGER);
    foreach ([
        ['Work permit', 'work_permit', '2026-10-01'],
        ['Nursing licence', 'licence', '2026-11-10'],
        ['First aid certificate', 'certificate', '2027-06-01'],
        ['Passport', 'identity', '2026-11-01'],
    ] as [$title, $category, $expires]) {
        $this->post($this->documentsUrl, ['file' => pdf(), 'category' => $category, 'title' => $title, 'expires_on' => $expires])->assertSessionHasNoErrors();
    }

    $this->get($this->workspaceUrl('acme'))->assertInertia(fn (Assert $page) => $page
        ->has('summary.documents', 3)
        ->where('summary.documents.0.title', 'Work permit')
        ->where('summary.documents.0.expiry', 'expired')
        ->where('summary.documents.1.title', 'Passport')
        ->where('summary.documents.2.expiry', 'expiring'));

    // Without the personal permission the passport is left out; without
    // the documents permission there is no list at all.
    $this->actingAsMember('acme', OFFICER);
    $this->get($this->workspaceUrl('acme'))->assertInertia(fn (Assert $page) => $page->has('summary.documents', 2));
    $this->actingAsMember('acme', ['hr.employees.view']);
    $this->get($this->workspaceUrl('acme'))->assertInertia(fn (Assert $page) => $page->where('summary.documents', null));
});
