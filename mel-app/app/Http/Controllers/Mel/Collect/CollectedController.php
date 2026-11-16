<?php

namespace App\Http\Controllers\Mel\Collect;

use App\Collect\Answers;
use App\Collect\Definition;
use App\Collect\Models\Attachment;
use App\Collect\Models\Form;
use App\Collect\Models\ReviewDecision;
use App\Collect\Models\Submission;
use App\Collect\Models\SubmissionRevision;
use App\Collect\Refused;
use App\Collect\Review;
use App\Collect\Rules;
use App\Collect\Submissions;
use App\Http\Controllers\Mel\Controller;
use App\Mel\AuditTrail;
use App\Mel\Models\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Collected submissions on the web: the list and review queue, one
 * submission rendered by its own form version, its photos, the review
 * steps and a reviewer's correction. Reach is App\Collect\Scope (404
 * outside it); personal answers are withheld on the server (Answers).
 */
class CollectedController extends Controller
{
    public const VIEWS = ['to_review', 'correction_requested', 'accepted', 'rejected', 'receiving', 'all'];

    public function __construct(private readonly Review $review, private readonly Submissions $submissions) {}

    public function index(Request $request): Response
    {
        $view = in_array($request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'to_review';
        $formId = is_string($request->query('form')) ? $request->query('form') : null;

        $query = $this->scope()->submissions()
            ->when($formId !== null, fn ($q) => $q->where('form_id', $formId))
            ->when($view === 'to_review', fn ($q) => $q->whereIn('status', Submission::TO_REVIEW))
            ->when(in_array($view, ['correction_requested', 'accepted', 'rejected', 'receiving'], true), fn ($q) => $q->where('status', $view))
            ->when($view === 'all', fn ($q) => $q->where('status', '<>', Submission::RECEIVING));

        $counts = $this->scope()->submissions()
            ->when($formId !== null, fn ($q) => $q->where('form_id', $formId))
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $page = $query->with(['form', 'author', 'unit', 'version'])->orderBy($view === 'to_review' ? 'received_at' : 'completed_at', $view === 'to_review' ? 'asc' : 'desc')
            ->paginate(50)->withQueryString();

        return Inertia::render('mel/collect/submissions/index', [
            'filters' => ['view' => $view, 'form' => $formId],
            'counts' => collect(Submission::STATUSES)->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)]),
            'submissions' => [
                'data' => collect($page->items())->map(fn (Submission $submission) => [
                    'id' => $submission->id,
                    'form' => $submission->form->name,
                    'version' => $submission->version->number,
                    'author' => $submission->author->name,
                    'unit' => $submission->unit?->code,
                    'status' => $submission->status,
                    'completedAt' => $submission->completed_at->toIso8601String(),
                    'receivedAt' => $submission->received_at->toIso8601String(),
                    'revised' => $submission->current_revision_id !== null,
                    'missingPhotos' => $submission->attachments_expected - $submission->attachments_received,
                ])->values(),
                'links' => ['prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
                'total' => $page->total(),
            ],
            'forms' => $this->scope()->forms()->orderBy('name')->get(['id', 'name'])->map(fn (Form $form) => ['value' => $form->id, 'label' => $form->name])->values(),
        ]);
    }

    public function show(string $submission): Response
    {
        $model = $this->scope()->submission($submission);
        $model->load(['form.programme', 'version', 'author', 'unit', 'reviewer', 'currentRevision', 'revisions.author', 'attachments', 'decisions.user']);
        $access = $this->access();
        $seesPersonal = $this->scope()->seesPersonalData();
        $user = $this->user();
        $ownWork = $model->author_id === $user->id && Settings::current()->collect_reviewer_must_differ;

        return Inertia::render('mel/collect/submissions/show', [
            'submission' => [
                'id' => $model->id,
                'label' => $model->label(),
                'form' => ['id' => $model->form_id, 'name' => $model->form->name, 'programme' => $model->form->programme?->label()],
                'version' => ['id' => $model->form_version_id, 'number' => $model->version->number],
                'status' => $model->status,
                'author' => $model->author->name,
                'unit' => $model->unit?->label(),
                'startedAt' => $model->started_at?->toIso8601String(),
                'completedAt' => $model->completed_at->toIso8601String(),
                'receivedAt' => $model->received_at->toIso8601String(),
                'appVersion' => $model->app_version,
                'consentGiven' => $model->consent_given,
                'place' => $seesPersonal && $model->lat !== null ? ['lat' => $model->lat, 'lng' => $model->lng, 'accuracy' => $model->accuracy] : null,
                'photos' => ['expected' => $model->attachments_expected, 'received' => $model->attachments_received],
                'reviewer' => $model->reviewer?->name,
                'reviewedAt' => $model->reviewed_at?->toIso8601String(),
                'revision' => $model->currentRevision->number ?? 0,
            ],
            'answers' => Answers::present($model, $seesPersonal),
            'personalHidden' => ! $seesPersonal,
            'revisions' => $model->revisions->map(fn (SubmissionRevision $revision) => [
                'number' => $revision->number,
                'changed' => $revision->changed,
                'reason' => $revision->reason,
                'author' => $revision->author->name,
                'at' => $revision->created_at->toIso8601String(),
            ])->values(),
            'decisions' => $model->decisions->map(fn (ReviewDecision $decision) => [
                'action' => $decision->action,
                'comment' => $decision->comment,
                'notes' => $decision->notes,
                'by' => $decision->user->name,
                'at' => $decision->created_at->toIso8601String(),
            ])->values(),
            // Editing a correction on the web: the version's definition and current answers.
            'correction' => $access->can('mel.collect.correct') && $seesPersonal && in_array($model->status, [Submission::SUBMITTED, Submission::UNDER_REVIEW, Submission::CORRECTION_REQUESTED], true)
                ? ['definition' => $model->version->definition, 'answers' => $model->currentAnswers(), 'today' => $model->completed_at->toDateString()]
                : null,
            'can' => [
                'review' => $access->can('mel.collect.review') && ! $ownWork,
                'reopen' => $access->can('mel.collect.review'),
                'correct' => $access->can('mel.collect.correct') && $seesPersonal,
            ],
            'ownWork' => $ownWork,
        ]);
    }

    public function photo(string $submission, string $attachment): StreamedResponse
    {
        $model = $this->scope()->submission($submission);
        // Photos are always personal data.
        abort_unless($this->scope()->seesPersonalData(), 403, 'Photos are personal data.');
        $file = Attachment::query()->where('submission_id', $model->id)->findOrFail($attachment);

        DB::transaction(fn () => app(AuditTrail::class)->record('collected.photo_viewed', $model->load('form'), $this->user(), ['question' => $file->question]));

        return Storage::disk('local')->response($file->path, $file->question.'.'.(Submissions::PHOTO_TYPES[$file->mime_type] ?? 'bin'), [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'",
        ], 'inline');
    }

    public function start(string $submission): RedirectResponse
    {
        $this->review->start($this->scope()->submission($submission), $this->user());

        return back()->with('success', 'Review started.');
    }

    public function accept(Request $request, string $submission): RedirectResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);
        $this->review->accept($this->scope()->submission($submission), $this->user(), $data['comment'] ?? null);

        return back()->with('success', 'Accepted. It now counts in analysis and exports of accepted data.');
    }

    public function reject(Request $request, string $submission): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']], ['reason.required' => 'Say why it is rejected.']);
        $this->review->reject($this->scope()->submission($submission), $this->user(), $data['reason']);

        return back()->with('success', 'Rejected. It is kept, and left out of accepted data.');
    }

    public function requestCorrection(Request $request, string $submission): RedirectResponse
    {
        $data = $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:1000'],
        ], ['comment.required' => 'Say what needs correcting.']);
        $model = $this->scope()->submission($submission);
        $model->load('version');
        $this->review->requestCorrection($model, $this->user(), $data['comment'], array_filter($data['notes'] ?? [], 'is_string'));

        return back()->with('success', 'Correction asked for. The collector sees it the next time their phone syncs.');
    }

    public function reopen(Request $request, string $submission): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']], ['reason.required' => 'Say why it is reopened.']);
        $this->review->reopen($this->scope()->submission($submission), $this->user(), $data['reason']);

        return back()->with('success', 'Reopened for review.');
    }

    public function correct(Request $request, string $submission): RedirectResponse
    {
        abort_unless($this->scope()->seesPersonalData(), 403, 'Correcting answers needs access to personal data.');
        $data = $request->validate([
            'answers' => ['present', 'array'],
            'reason' => ['required', 'string', 'max:5000'],
            'base_revision' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $this->submissions->correct($this->scope()->submission($submission), (array) $request->input('answers', []), $data['reason'], (int) $data['base_revision'], $this->user(), asReviewer: true);
        } catch (Refused $refused) {
            $messages = ['answers' => $refused->getMessage()];
            foreach ((array) ($refused->extra['errors'] ?? []) as $name => $code) {
                $messages["answers.{$name}"] = self::errorText((string) $code);
            }
            throw ValidationException::withMessages($messages);
        }

        return back()->with('success', 'Correction saved as a new revision. The original answers are kept.');
    }

    /**
     * A per-form summary of accepted data: counts and shares for choices,
     * min / median / max for numbers, answered counts. Free text, photos and
     * places are not summarized; personal questions only with access.
     */
    public function summary(string $form): Response
    {
        $model = $this->scope()->form($form);
        $model->load('currentVersion');
        $seesPersonal = $this->scope()->seesPersonalData();
        $accepted = $this->scope()->submissions()->where('form_id', $model->id)->where('status', Submission::ACCEPTED)
            ->with(['currentRevision', 'version'])->limit(20_000)->get();
        $definition = $model->currentVersion->definition ?? $model->draft_definition;

        $questions = [];
        foreach (Definition::questions($definition) as $name => $question) {
            $type = (string) $question['type'];
            if (in_array($type, ['text', 'textarea', 'photo', 'geopoint'], true) || (Definition::isPersonal($question) && ! $seesPersonal)) {
                continue;
            }
            $values = $accepted->map(function (Submission $submission) use ($name, $type) {
                $own = Definition::questions($submission->version->definition)[$name] ?? null;

                return ($own['type'] ?? null) === $type ? ($submission->currentAnswers()[$name] ?? null) : null;
            })->reject(fn ($value) => Rules::isEmpty($value))->values();

            $row = ['name' => $name, 'label' => is_string($question['label'] ?? null) ? $question['label'] : $name, 'type' => $type, 'answered' => $values->count()];
            if (in_array($type, ['select_one', 'yes_no', 'select_multiple'], true)) {
                $row['choices'] = collect(Definition::choices($definition, $question))->map(fn (string $label, string $choice) => [
                    'label' => $label,
                    'count' => $values->filter(fn ($value) => is_array($value) ? in_array($choice, $value, true) : $value === $choice)->count(),
                ])->values();
            } elseif (in_array($type, ['integer', 'decimal'], true)) {
                $sorted = $values->map(fn ($v) => (float) $v)->sort()->values();
                $row['stats'] = $sorted->isEmpty() ? null : ['min' => $sorted->first(), 'median' => $sorted->median(), 'max' => $sorted->last()];
            }
            $questions[] = $row;
        }

        return Inertia::render('mel/collect/forms/summary', [
            'form' => ['id' => $model->id, 'name' => $model->name, 'version' => $model->currentVersion?->number],
            'accepted' => $accepted->count(),
            'questions' => $questions,
            'personalHidden' => ! $seesPersonal,
        ]);
    }

    public static function errorText(string $code): string
    {
        return match ($code) {
            'required' => 'An answer is required.',
            'type' => 'This answer is not of the right kind.',
            'choice' => 'Choose one of the options.',
            'min' => 'Too small.',
            'max' => 'Too large.',
            'min_length' => 'Too short.',
            'max_length' => 'Too long.',
            'pattern' => 'Not in the expected format.',
            'min_choices' => 'Choose more options.',
            'max_choices' => 'Choose fewer options.',
            'photo_locked' => 'Photos cannot be changed in a correction.',
            default => 'Not valid.',
        };
    }
}
