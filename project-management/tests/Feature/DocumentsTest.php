<?php

use App\Access\Catalog;
use App\Actions\Documents;
use App\Actions\Organizations;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Support\Modules;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake(Documents::DISK);
});

function documentDetails(array $overrides = []): array
{
    return ['title' => 'Finance policy', 'category' => 'policy', ...$overrides];
}

function documentFile(string $name = 'policy.pdf', string $content = 'first'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function addedDocument(array $overrides = []): Document
{
    test()->post(test()->organizationUrl('acme', 'documents'), [...documentDetails($overrides), 'file' => documentFile()])->assertSessionHasNoErrors();

    $title = documentDetails($overrides)['title'];

    return test()->asTenant('acme', fn () => Document::query()->where('title', $title)->firstOrFail());
}

it('adds a document with its first version, and records it', function () {
    $this->actingAsMember('acme', ['documents.view', 'documents.manage']);

    $this->post($this->organizationUrl('acme', 'documents'), [...documentDetails(), 'file' => documentFile()])->assertRedirect();

    $this->asTenant('acme', function () {
        $document = Document::query()->firstOrFail();
        $version = $document->versions()->firstOrFail();

        expect($document->current_version)->toBe(1)
            ->and($document->confidential)->toBeFalse()
            ->and($version->version)->toBe(1)
            ->and($version->original_name)->toBe('policy.pdf')
            ->and($version->sha256)->toBe(hash('sha256', 'first'))
            ->and($version->path)->toStartWith("organizations/{$document->organization_id}/documents/{$document->id}/");
        Storage::disk(Documents::DISK)->assertExists($version->path);
        expect(AuditEvent::query()->where('action', 'document.created')->where('subject_id', $document->id)->exists())->toBeTrue();
    });
});

it('keeps every version, numbered, and downloads each as it was', function () {
    $this->actingAsMember('acme', ['documents.view', 'documents.manage']);
    $document = addedDocument();
    $url = fn (string $path = '') => $this->organizationUrl('acme', "documents/{$document->id}{$path}");

    $this->post($url('/versions'), ['file' => documentFile('policy-v2.pdf', 'second'), 'note' => 'Raised the limits'])->assertSessionHasNoErrors();

    $this->get($url())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/documents/show')
        ->where('document.version', 2)
        ->has('versions', 2)
        ->where('versions.0.version', 2)
        ->where('versions.0.note', 'Raised the limits')
        ->where('versions.1.version', 1));

    $first = $this->asTenant('acme', fn () => $document->versions()->where('version', 1)->firstOrFail());
    $response = $this->get($url("/versions/{$first->id}"))->assertOk();
    expect($response->streamedContent())->toBe('first');
    $this->asTenant('acme', fn () => expect(fn () => $first->update(['note' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $first->delete())->toThrow(LogicException::class));
});

it('refuses files a browser could run, and missing files', function () {
    $this->actingAsMember('acme', ['documents.view', 'documents.manage']);
    $url = $this->organizationUrl('acme', 'documents');

    foreach (['page.html' => '<script>alert(1)</script>', 'drawing.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'run.php' => '<?php echo 1;'] as $name => $content) {
        $this->from($url)->post($url, [...documentDetails(), 'file' => UploadedFile::fake()->createWithContent($name, $content)])->assertSessionHasErrors('file');
    }
    $this->from($url)->post($url, documentDetails())->assertSessionHasErrors('file');

    $this->asTenant('acme', fn () => expect(Document::query()->count())->toBe(0));
});

it('needs documents.manage to change anything', function () {
    $this->actingAsMember('acme', ['documents.view', 'documents.manage']);
    $document = addedDocument();

    $this->actingAsMember('acme', ['documents.view']);
    $url = fn (string $path = '') => $this->organizationUrl('acme', "documents{$path}");

    $this->get($url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false)->has('documents.data', 1));
    $this->post($url(), [...documentDetails(), 'file' => documentFile()])->assertForbidden();
    $this->put($url("/{$document->id}"), documentDetails(['title' => 'Mine']))->assertForbidden();
    $this->post($url("/{$document->id}/versions"), ['file' => documentFile()])->assertForbidden();
    $this->post($url("/{$document->id}/archive"))->assertForbidden();

    $this->actingAsMember('acme', ['projects.view']);
    $this->get($url())->assertForbidden();
});

it('hides confidential and archived documents from those who only read', function () {
    $this->actingAsMember('acme', ['documents.view', 'documents.manage']);
    $secret = addedDocument(['title' => 'Salary review', 'confidential' => '1']);
    $old = addedDocument(['title' => 'Old policy']);
    $this->post($this->organizationUrl('acme', "documents/{$old->id}/archive"))->assertSessionHasNoErrors();
    $open = addedDocument(['title' => 'Open policy']);
    $version = $this->asTenant('acme', fn () => $secret->versions()->firstOrFail());
    $openVersion = $this->asTenant('acme', fn () => $open->versions()->firstOrFail());

    $url = fn (string $path = '') => $this->organizationUrl('acme', "documents{$path}");
    $this->get($url())->assertInertia(fn (Assert $page) => $page->has('documents.data', 3));

    // Who read something confidential is recorded; an open document is not.
    $this->get($url("/{$secret->id}/versions/{$version->id}"))->assertOk();
    $this->get($url("/{$open->id}/versions/{$openVersion->id}"))->assertOk();
    expect(AuditEvent::query()->where('action', 'document.downloaded')->pluck('subject_id')->all())->toBe([$secret->id]);

    $this->actingAsMember('acme', ['documents.view']);
    $this->get($url())->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)->where('documents.data.0.title', 'Open policy'));
    $this->get($url("/{$secret->id}"))->assertNotFound();
    $this->get($url("/{$secret->id}/versions/{$version->id}"))->assertNotFound();
    $this->get($url("/{$old->id}"))->assertNotFound();
    $this->get($this->organizationUrl('acme', 'search?q=salary'))->assertInertia(fn (Assert $page) => $page->has('groups', 0));
    $this->get($this->organizationUrl('acme', 'search?q=open'))->assertInertia(fn (Assert $page) => $page->where('groups.0.title', 'Documents'));
});

it('takes no new version while archived, and comes back on restore', function () {
    $this->actingAsMember('acme', ['documents.view', 'documents.manage']);
    $document = addedDocument();
    $url = fn (string $path) => $this->organizationUrl('acme', "documents/{$document->id}{$path}");

    $this->post($url('/archive'))->assertSessionHasNoErrors();
    $this->from($url(''))->post($url('/versions'), ['file' => documentFile()])->assertSessionHasErrors('file');

    $this->post($url('/restore'))->assertSessionHasNoErrors();
    $this->post($url('/versions'), ['file' => documentFile()])->assertSessionHasNoErrors();
    $this->asTenant('acme', fn () => expect($document->refresh()->current_version)->toBe(2));
});

it('records only what changed in a document', function () {
    $this->actingAsMember('acme', ['documents.view', 'documents.manage']);
    $document = addedDocument();

    $this->put($this->organizationUrl('acme', "documents/{$document->id}"), documentDetails(['title' => 'Finance manual']))->assertSessionHasNoErrors();

    expect(AuditEvent::query()->where('action', 'document.updated')->firstOrFail()->properties)
        ->toBe(['before' => ['title' => 'Finance policy'], 'after' => ['title' => 'Finance manual']]);
});

it('keeps documents to their organization', function () {
    $this->actingAsMember('acme', ['documents.view', 'documents.manage']);
    $document = addedDocument();
    $version = $this->asTenant('acme', fn () => $document->versions()->firstOrFail());

    $this->actingAsMember('other-org', ['documents.view', 'documents.manage']);
    $url = fn (string $path = '') => $this->organizationUrl('other-org', "documents{$path}");

    $this->get($url())->assertInertia(fn (Assert $page) => $page->has('documents.data', 0));
    $this->get($url("/{$document->id}"))->assertNotFound();
    $this->get($url("/{$document->id}/versions/{$version->id}"))->assertNotFound();
    $this->put($url("/{$document->id}"), documentDetails(['title' => 'Mine']))->assertNotFound();
    $this->post($url("/{$document->id}/versions"), ['file' => documentFile()])->assertNotFound();
});

it('does not serve a version through another document', function () {
    $this->actingAsMember('acme', ['documents.view', 'documents.manage']);
    $first = addedDocument(['title' => 'First']);
    $second = addedDocument(['title' => 'Second', 'confidential' => '1']);
    $secret = $this->asTenant('acme', fn () => $second->versions()->firstOrFail());

    $this->get($this->organizationUrl('acme', "documents/{$first->id}/versions/{$secret->id}"))->assertNotFound();
});

it('refuses documents while the module is off', function () {
    $owner = $this->actingAsMember('acme', [], owner: true);
    $organization = Organization::query()->where('slug', 'acme')->firstOrFail();
    Organizations::setModule($organization, Modules::DOCUMENTS, false, $owner);

    $this->get($this->organizationUrl('acme', 'documents'))->assertForbidden();
});

it('gives the system roles what they should have', function () {
    $roles = collect(['viewer', 'auditor', 'manager', 'admin'])->mapWithKeys(fn (string $role) => [$role => ['permissions' => Catalog::rolePermissions($role)]]);

    expect($roles['viewer']['permissions'])->toContain('documents.view')->not->toContain('documents.manage')
        ->and($roles['auditor']['permissions'])->toContain('documents.view')->not->toContain('documents.manage')
        ->and($roles['manager']['permissions'])->toContain('documents.view')->not->toContain('documents.manage')
        ->and($roles['admin']['permissions'])->toContain('documents.manage');
});

it('keeps version rows and their parent in one organization at the database', function () {
    $this->actingAsMember('acme', ['documents.view', 'documents.manage']);
    $document = addedDocument();
    $this->actingAsMember('other-org', []);
    $other = Organization::query()->where('slug', 'other-org')->firstOrFail();

    expect(fn () => DB::transaction(fn () => DB::table('document_versions')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $other->id,
        'document_id' => $document->id,
        'version' => 9,
        'original_name' => 'x',
        'path' => 'x',
        'mime_type' => 'x',
        'size' => 1,
        'sha256' => str_repeat('0', 64),
        'created_at' => now(),
    ])))->toThrow(QueryException::class);

    $this->asTenant('acme', fn () => expect(DocumentVersion::query()->count())->toBe(1));
});
