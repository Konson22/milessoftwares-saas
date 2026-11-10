<?php

namespace App\Http\Controllers\Documents;

use App\Actions\Documents;
use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The organization's documents. Someone without documents.manage finds a
 * confidential or archived document does not exist (404), by link as in the list.
 */
class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $manage = $this->can($request, 'documents.manage');
        $category = $request->string('category')->toString();
        $category = array_key_exists($category, Document::CATEGORIES) ? $category : '';

        $documents = Document::query()
            ->when(! $manage, fn ($query) => $query->openToAll())
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->orderByRaw('case when archived_at is null then 0 else 1 end')
            ->orderBy('title')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('portal/documents/index', [
            'documents' => [
                'data' => collect($documents->items())->map(fn (Document $document) => $this->row($document)),
                'currentPage' => $documents->currentPage(),
                'lastPage' => $documents->lastPage(),
                'total' => $documents->total(),
            ],
            'categories' => $this->categories(),
            'filters' => ['category' => $category],
            'canManage' => $manage,
        ]);
    }

    public function show(Request $request, Document $document): Response
    {
        $this->requireOpen($request, $document);

        return Inertia::render('portal/documents/show', [
            'document' => $this->row($document),
            'versions' => $document->versions()->with('uploader:id,name')->orderByDesc('version')->get()
                ->map(fn (DocumentVersion $version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'name' => $version->original_name,
                    'size' => $version->size,
                    'sha256' => $version->sha256,
                    'note' => $version->note,
                    'by' => $version->uploader?->name,
                    'at' => $version->created_at->toIso8601String(),
                ]),
            'categories' => $this->categories(),
            'canManage' => $this->can($request, 'documents.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', ...Document::RULES]]);
        $document = Documents::create($this->details($request), $request->file('file'), $this->member($request));

        return to_route('documents.show', $document)->with('success', "{$document->title} added.");
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        Documents::update($document, $this->details($request), $this->member($request));

        return back()->with('success', 'Document saved.');
    }

    public function addVersion(Request $request, Document $document): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', ...Document::RULES],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $version = Documents::addVersion($document, $request->file('file'), $this->text($validated['note'] ?? null), $this->member($request));

        return back()->with('success', "Version {$version->version} added.");
    }

    public function archive(Request $request, Document $document): RedirectResponse
    {
        Documents::archive($document, $this->member($request));

        return back()->with('success', "{$document->title} archived.");
    }

    public function restore(Request $request, Document $document): RedirectResponse
    {
        Documents::restore($document, $this->member($request));

        return back()->with('success', "{$document->title} restored.");
    }

    public function download(Request $request, Document $document, DocumentVersion $version): StreamedResponse
    {
        $this->requireOpen($request, $document);

        // Who read a confidential document is worth knowing.
        if ($document->confidential) {
            Audit::record('document.downloaded', $document, ['title' => $document->title, 'version' => $version->version]);
        }

        return Storage::disk(Documents::DISK)->download($version->path, $version->original_name, [
            'Content-Type' => $version->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function requireOpen(Request $request, Document $document): void
    {
        abort_if(($document->confidential || $document->archived_at !== null) && ! $this->can($request, 'documents.manage'), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Document $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'category' => $document->category,
            'description' => $document->description,
            'confidential' => $document->confidential,
            'archived' => $document->archived_at !== null,
            'version' => $document->current_version,
            'updatedAt' => $document->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function categories(): array
    {
        return array_map(
            fn (string $value, string $label) => ['value' => $value, 'label' => $label],
            array_keys(Document::CATEGORIES),
            Document::CATEGORIES,
        );
    }

    /**
     * @return array{title: string, category: string, description: string|null, confidential: bool}
     */
    private function details(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(array_keys(Document::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:2000'],
            'confidential' => ['nullable', 'boolean'],
        ]);

        return [
            'title' => trim($validated['title']),
            'category' => $validated['category'],
            'description' => $this->text($validated['description'] ?? null),
            'confidential' => filter_var($validated['confidential'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
