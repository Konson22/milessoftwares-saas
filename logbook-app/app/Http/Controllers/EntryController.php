<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\Attachments;
use App\Logbook\Entries;
use App\Logbook\Fields;
use App\Logbook\Models\Attachment;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Entry;
use App\Logbook\Models\EntryReview;
use App\Logbook\Models\EntryRevision;
use App\Logbook\Models\LogSheet;
use App\Logbook\Reports;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EntryController extends Controller
{
    /**
     * Entries: the member's own by default ("My entries"), or everything
     * they may read, with filters and keyword search.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate(Reports::rules());
        $scope = $request->input('scope') === 'all' ? 'all' : 'mine';
        $query = Reports::query([...$filters, 'mine' => $scope === 'mine'], includeDrafts: $scope === 'mine');

        $page = $query->with(['logbook:id,name', 'sheet:id,name,code', 'creator:id,name', 'version:id,fields'])
            ->orderByRaw("case status when 'returned' then 0 when 'draft' then 1 else 2 end")
            ->latest('occurred_at')->paginate(25)->withQueryString();

        return Inertia::render('entries/index', [
            'scope' => $scope,
            'filters' => $filters,
            'entries' => $page->through(fn (Entry $e) => self::entryRow($e)),
            'options' => self::filterOptions(),
            'writableSheets' => self::writableSheets(),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $sheetId = $request->string('sheet')->toString();
        if (! Str::isUuid($sheetId)) {
            return Inertia::render('entries/pick', ['sheets' => self::writableSheets()]);
        }
        $sheet = Access::writableSheets()->findOrFail($sheetId);
        $version = $sheet->publishedVersion;
        abort_if($version === null, 404);

        return Inertia::render('entries/form', [
            'sheet' => $this->sheetData($sheet),
            'fields' => $version->fields,
            'version' => $version->version,
            'entry' => null,
            'values' => (object) Fields::defaults($version->fields),
            'occurredAt' => now(Workspace::current()->timezone ?? 'UTC')->format('Y-m-d\TH:i'),
            // One key per opened form: a double click or a resent request makes one entry.
            'idempotencyKey' => (string) Str::uuid7(),
            'members' => WorkspaceMember::options(),
            'files' => (object) [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sheet_id' => ['required', 'uuid'],
            'occurred_at' => ['required', 'date'],
            'values' => ['present', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'submit' => ['boolean'],
        ]);
        $sheet = Access::writableSheets()->whereKey((string) $data['sheet_id'])->firstOrFail();
        $entry = Entries::create($sheet, $this->user(), $data['values'], $this->occurredAt($data['occurred_at']), $data['idempotency_key'] ?? null, (bool) ($data['submit'] ?? false));

        return $entry->status === 'draft'
            ? to_route('entries.edit', $entry->id)->with('success', 'Draft saved. Add files or finish it later.')
            : to_route('entries.show', $entry->id)->with('success', self::submittedMessage($entry));
    }

    public function show(string $entry): Response
    {
        $entry = Access::entry($entry);
        $entry->load(['logbook:id,name', 'sheet', 'version', 'creator:id,name', 'reviewer:id,name']);
        $files = $entry->attachments()->get();
        $me = $this->user();

        $revisions = $entry->revisions()->with(['version', 'submitter:id,name'])->get();
        $previous = null;
        $revisionRows = [];
        foreach ($revisions->reverse() as $revision) {
            /** @var EntryRevision $revision */
            // Fields whose answer differs from the previous submission.
            $changed = [];
            if ($previous !== null) {
                foreach (array_unique([...array_keys($revision->values), ...array_keys($previous->values)]) as $name) {
                    if (($revision->values[$name] ?? null) !== ($previous->values[$name] ?? null)) {
                        $changed[] = $name;
                    }
                }
            }
            $revisionRows[] = [
                'revision' => $revision->revision,
                'version' => $revision->version->version,
                'submittedAt' => self::time($revision->submitted_at),
                'submittedBy' => $revision->submitter->name,
                'occurredAt' => self::time($revision->occurred_at),
                'answers' => self::answers($revision->version->fields, $revision->values, $files),
                'changed' => $changed,
            ];
            $previous = $revision;
        }

        $canReview = $entry->status === 'submitted' && $entry->created_by !== $me->id
            && Gate::allows('logbook.entries.review') && Access::reachesSheet($entry->sheet, 'review');

        return Inertia::render('entries/show', [
            'entry' => [
                ...self::entryRow($entry),
                'version' => $entry->version->version,
                'latestVersion' => $entry->sheet->publishedVersion?->version,
                'revision' => $entry->revision,
                'reviewedAt' => self::time($entry->reviewed_at),
                'reviewer' => $entry->reviewer?->name,
                'isMine' => $entry->created_by === $me->id,
                'answers' => self::answers($entry->version->fields, $entry->values, $files),
            ],
            'revisions' => array_reverse($revisionRows),
            'reviews' => $entry->reviews()->get()->map(fn (EntryReview $r) => [
                'id' => $r->id, 'revision' => $r->revision, 'decision' => $r->decision, 'comment' => $r->comment,
                'reviewer' => $r->reviewer_name, 'decidedAt' => self::time($r->decided_at),
            ]),
            'history' => AuditEvent::query()->where('subject_type', 'entry')->where('subject_id', $entry->id)->orderBy('created_at')->get()
                ->map(fn (AuditEvent $e) => ['id' => $e->id, 'action' => $e->action, 'user' => $e->user_name, 'details' => $e->details, 'at' => self::time($e->created_at)]),
            'can' => [
                'edit' => $entry->editable() && $entry->created_by === $me->id,
                'discard' => $entry->status === 'draft' && $entry->revision === 0 && $entry->created_by === $me->id,
                'review' => $canReview,
            ],
        ]);
    }

    public function edit(string $entry): Response
    {
        $entry = Access::entry($entry);
        abort_unless($entry->created_by === $this->user()->id, 403, 'Only the author changes an entry.');
        abort_unless($entry->editable(), 409, 'This entry can no longer be changed.');
        $files = $entry->attachments()->whereNull('removed_at')->get();
        $lastReturn = $entry->status === 'returned' ? $entry->reviews()->where('decision', 'returned')->first() : null;

        return Inertia::render('entries/form', [
            'sheet' => $this->sheetData($entry->sheet),
            'fields' => $entry->version->fields,
            'version' => $entry->version->version,
            'entry' => [
                'id' => $entry->id, 'reference' => $entry->reference, 'status' => $entry->status, 'revision' => $entry->revision,
                'returnComment' => $lastReturn?->comment, 'returnedBy' => $lastReturn?->reviewer_name,
            ],
            'values' => (object) $entry->values,
            'occurredAt' => $entry->occurred_at->setTimezone(Workspace::current()->timezone ?? 'UTC')->format('Y-m-d\TH:i'),
            'idempotencyKey' => null,
            'members' => WorkspaceMember::options(),
            'files' => (object) $files->groupBy('field')->map(fn ($group) => $group->map(fn (Attachment $a) => [
                'id' => $a->id, 'name' => $a->name, 'size' => $a->size, 'mime' => $a->mime, 'removed' => false,
            ])->values())->all(),
        ]);
    }

    public function update(Request $request, string $entry): RedirectResponse
    {
        $entry = Access::entry($entry);
        $data = $request->validate([
            'occurred_at' => ['required', 'date'],
            'values' => ['present', 'array'],
            'submit' => ['boolean'],
        ]);
        $entry = Entries::update($entry, $this->user(), $data['values'], $this->occurredAt($data['occurred_at']));

        if ($data['submit'] ?? false) {
            $entry = Entries::submit($entry, $this->user());

            return to_route('entries.show', $entry->id)->with('success', self::submittedMessage($entry));
        }

        return back()->with('success', 'Draft saved.');
    }

    public function submit(string $entry): RedirectResponse
    {
        $entry = Entries::submit(Access::entry($entry), $this->user());

        return to_route('entries.show', $entry->id)->with('success', self::submittedMessage($entry));
    }

    public function destroy(string $entry): RedirectResponse
    {
        Entries::discard(Access::entry($entry), $this->user());

        return to_route('entries.index')->with('success', 'Draft discarded.');
    }

    public function attach(Request $request, string $entry): RedirectResponse
    {
        $entry = Access::entry($entry);
        $data = $request->validate([
            'field' => ['required', 'string', 'max:64'],
            'file' => ['required', 'file', 'max:'.Attachments::MAX_KILOBYTES, 'mimes:'.Attachments::MIMES],
        ]);
        Attachments::add($entry, $data['field'], $data['file'], $this->user());

        return back()->with('success', 'File attached.');
    }

    public function detach(string $attachment): RedirectResponse
    {
        $attachment = Attachment::query()->findOrFail($attachment);
        Access::entry($attachment->log_entry_id);
        Attachments::remove($attachment, $this->user());

        return back()->with('success', 'File removed.');
    }

    /** A file, to someone who can read its entry (404 otherwise); never a public URL. */
    public function download(Request $request, string $attachment): StreamedResponse
    {
        $attachment = Attachment::query()->findOrFail($attachment);
        Access::entry($attachment->log_entry_id);
        abort_unless(Storage::disk(Attachments::DISK)->exists($attachment->path), 404);

        $inline = $request->boolean('inline') && in_array($attachment->mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);
        $headers = ['Content-Type' => $attachment->mime ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        return $inline
            ? Storage::disk(Attachments::DISK)->response($attachment->path, $attachment->name, $headers)
            : Storage::disk(Attachments::DISK)->download($attachment->path, $attachment->name, $headers);
    }

    /** One entry, its answers, revisions and reviews, as a PDF. */
    public function pdf(string $entry): HttpResponse
    {
        $entry = Access::entry($entry);
        abort_if($entry->status === 'draft', 404);
        $entry->load(['logbook:id,name', 'sheet', 'version', 'creator:id,name']);
        $files = $entry->attachments()->get();

        return Pdf::loadView('logbook.entry-pdf', [
            'organization' => Workspace::current()?->display_name,
            'entry' => $entry,
            'answers' => self::answers($entry->version->fields, $entry->values, $files),
            'reviews' => $entry->reviews()->get(),
            'timezone' => Workspace::current()->timezone ?? 'UTC',
            'printedBy' => $this->user()->name,
        ])->download(Str::slug(($entry->reference ?? 'entry').' '.$entry->sheet->name).'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetData(LogSheet $sheet): array
    {
        return [
            'id' => $sheet->id, 'name' => $sheet->name, 'code' => $sheet->code, 'instructions' => $sheet->instructions,
            'reviewRequired' => $sheet->review_required, 'logbook' => ['id' => $sheet->logbook_id, 'name' => $sheet->logbook->name],
        ];
    }

    /** A local date-time from the form, in the workspace time zone. */
    private function occurredAt(string $value): string
    {
        return CarbonImmutable::parse($value, Workspace::current()->timezone ?? 'UTC')->utc()->toIso8601String();
    }

    private static function submittedMessage(Entry $entry): string
    {
        return $entry->status === 'finalized'
            ? "Entry {$entry->reference} recorded. This sheet has no review step."
            : "Entry {$entry->reference} submitted for review.";
    }
}
