<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HomeController;
use App\Models\User;
use App\Modules\Documents\Actions\ManageDocuments;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Models\Document;
use App\Modules\Employees\Models\Employee;
use App\Support\StaffScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Someone's documents: the list, uploading, downloading and removing.
 *
 * Documents follow the staff they belong to (hr.documents.* where the
 * employee works); identity documents also need the organization-wide
 * personal permissions. A file is never linked to directly: the page
 * links to "open", which checks access and redirects to a download link
 * signed for a few minutes, and the download checks access again.
 */
class DocumentController extends Controller
{
    /** Upload limit in kilobytes; nginx allows 10 MB per request. */
    public const MAX_KILOBYTES = 8 * 1024;

    public const TYPES = ['pdf', 'jpg', 'jpeg', 'png'];

    public const LINK_MINUTES = 5;

    /** The same window as the home page's "ending soon". */
    public const EXPIRING_DAYS = HomeController::SOON_DAYS;

    public function __construct(
        private readonly StaffScope $scope,
        private readonly ManageDocuments $documents,
    ) {}

    public function index(Employee $employee): Response
    {
        $this->authorizeFor($employee, 'hr.documents.view');
        $personal = $this->scope->organizationWide('hr.personal.view');
        $manage = $this->scope->allows('hr.documents.manage', $employee);
        $personalManage = $this->scope->organizationWide('hr.personal.manage');
        $today = CarbonImmutable::today();

        $all = $employee->documents()
            ->with(['uploader:id,name', 'remover:id,name'])
            ->orderByRaw('removed_at is not null')
            ->orderByDesc('created_at')
            ->get();
        $visible = $all->filter(fn (Document $document) => $personal || ! $document->category->restricted());

        return Inertia::render('employees/documents', [
            'employee' => [
                'id' => $employee->id,
                'number' => $employee->employee_number,
                'name' => $employee->displayName(),
                'status' => $employee->status,
            ],
            'documents' => $visible->values()->map(fn (Document $document) => [
                'id' => $document->id,
                'category' => $document->category->label(),
                'title' => $document->title,
                'issuedOn' => $document->issued_on?->toDateString(),
                'expiresOn' => $document->expires_on?->toDateString(),
                'expiry' => $this->expiry($document, $today),
                'note' => $document->note,
                'fileName' => $document->original_name,
                'size' => $document->size_bytes,
                'uploadedBy' => $document->uploader->name,
                'uploadedAt' => $document->created_at->toIso8601String(),
                'removedBy' => $document->remover?->name,
                'removedAt' => $document->removed_at?->toIso8601String(),
                'canRemove' => ! $document->isRemoved() && $manage && ($personalManage || ! $document->category->restricted()),
            ]),
            'hidden' => $all->count() - $visible->count(),
            'canUpload' => $manage,
            'categories' => array_values(array_filter(
                DocumentCategory::options(),
                fn (array $option) => $personalManage || ! DocumentCategory::from($option['value'])->restricted(),
            )),
            'limits' => ['types' => self::TYPES, 'megabytes' => self::MAX_KILOBYTES / 1024],
        ]);
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeFor($employee, 'hr.documents.manage');
        $validated = $request->validate([
            // mimes checks what the file is; extensions what it is called.
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'mimes:'.implode(',', self::TYPES), 'extensions:'.implode(',', self::TYPES)],
            'category' => ['required', Rule::enum(DocumentCategory::class)],
            'title' => ['required', 'string', 'max:160'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'file.mimes' => 'Upload a PDF, JPEG or PNG file.',
            'file.extensions' => 'Upload a PDF, JPEG or PNG file.',
            'file.max' => 'The file can be up to '.(self::MAX_KILOBYTES / 1024).' MB.',
        ]);

        $category = DocumentCategory::from($validated['category']);
        abort_if($category->restricted() && ! $this->scope->organizationWide('hr.personal.manage'), 403, 'Identity documents need the personal details permission.');

        $this->documents->upload($employee, $validated['file'], [
            'category' => $category,
            'title' => $validated['title'],
            'issued_on' => $validated['issued_on'] ?? null,
            'expires_on' => $validated['expires_on'] ?? null,
            'note' => $validated['note'] ?? null,
        ], $this->user($request));

        return back()->with('success', 'Document uploaded.');
    }

    /**
     * Checks access, then hands over a download link that works for a few minutes.
     */
    public function open(Document $document): RedirectResponse
    {
        $this->authorizeDocument($document, 'view');

        return redirect()->to(URL::temporarySignedRoute(
            'documents.download',
            CarbonImmutable::now()->addMinutes(self::LINK_MINUTES),
            ['document' => $document->id],
            absolute: false,
        ));
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->authorizeDocument($document, 'view');
        // A row without its file (a partial restore) is a missing document, not an error.
        abort_unless(Storage::disk(Document::DISK)->exists($document->path), 404);
        $this->documents->downloaded($document, $this->user($request));

        return Storage::disk(Document::DISK)->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorizeDocument($document, 'manage');
        $this->documents->remove($document, $this->user($request));

        return back()->with('success', 'Document removed.');
    }

    /**
     * expired, expiring (within EXPIRING_DAYS) or null.
     */
    public static function expiry(Document $document, CarbonImmutable $today): ?string
    {
        if ($document->expires_on === null || $document->isRemoved()) {
            return null;
        }
        if ($document->expires_on->lt($today)) {
            return 'expired';
        }

        return $document->expires_on->lte($today->addDays(self::EXPIRING_DAYS)) ? 'expiring' : null;
    }

    /**
     * @param  'view'|'manage'  $ability
     */
    private function authorizeDocument(Document $document, string $ability): void
    {
        abort_if($document->isRemoved(), 404);
        $this->authorizeFor($document->employee, "hr.documents.{$ability}");

        if ($document->category->restricted()) {
            abort_unless($this->scope->organizationWide("hr.personal.{$ability}"), 404);
        }
    }

    private function authorizeFor(Employee $employee, string $permission): void
    {
        abort_unless($this->scope->allows('hr.employees.view', $employee), 404);
        abort_unless($this->scope->allows($permission, $employee), 403);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
