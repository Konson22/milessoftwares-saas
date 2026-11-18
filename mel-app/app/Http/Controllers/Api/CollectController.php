<?php

namespace App\Http\Controllers\Api;

use App\Collect\Answers;
use App\Collect\Definition;
use App\Collect\Models\Assignment;
use App\Collect\Models\Form;
use App\Collect\Models\FormVersion;
use App\Collect\Models\ReviewDecision;
use App\Collect\Models\Submission;
use App\Collect\Refused;
use App\Collect\Submissions;
use App\Http\Controllers\Mel\Collect\AnalyticsController;
use App\Mel\Models\Settings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The field app's API (ADR-0019 §6.2, ADR-0012 §9), at
 * {workspace}/mel/api/v1/collect. Bearer token (platform.api), the
 * collector's own assignments only, mel.collect.submit on every route.
 *
 * Everything here is safe to repeat: downloads are reads, a submission is
 * keyed by the phone's id, a file by its question and hash, a correction
 * by the revision it started from.
 */
class CollectController
{
    /** The newest form definition schema this server sends. */
    public const SCHEMA_VERSION = Definition::SCHEMA_VERSION;

    public function __construct(private readonly Submissions $submissions) {}

    /**
     * The collector's kit: their live assignments, each with its form's
     * current version id and hash, so the phone downloads what it lacks.
     */
    public function assignments(Request $request): JsonResponse
    {
        $user = self::user($request);
        $settings = Settings::current();
        $assignments = Assignment::query()->live()->where('user_id', $user->id)
            ->with(['form.currentVersion', 'form.programme', 'unit', 'form.unit'])
            ->get()
            ->filter(fn (Assignment $assignment) => $assignment->acceptsSubmissions());

        // The collector's own records per form, by review state, in one
        // query: the task's progress, also for records the phone has since
        // removed after its keep-on-device days.
        $counts = [];
        if ($assignments->isNotEmpty()) {
            Submission::query()
                ->where('author_id', $user->id)
                ->whereIn('form_id', $assignments->pluck('form_id')->unique()->values())
                ->selectRaw('form_id, status, count(*) as n')
                ->groupBy('form_id', 'status')
                ->get()
                ->each(function (Submission $row) use (&$counts) {
                    $counts[$row->form_id][$row->status] = (int) $row->getAttribute('n');
                });
        }
        $progress = function (string $formId) use ($counts): array {
            $c = $counts[$formId] ?? [];

            return [
                'received' => array_sum($c),
                'pending' => ($c[Submission::RECEIVING] ?? 0) + ($c[Submission::SUBMITTED] ?? 0) + ($c[Submission::UNDER_REVIEW] ?? 0),
                'accepted' => $c[Submission::ACCEPTED] ?? 0,
                'correction' => $c[Submission::CORRECTION_REQUESTED] ?? 0,
                'rejected' => $c[Submission::REJECTED] ?? 0,
            ];
        };

        // The collector's own submissions per day over the last 30 days, for
        // the phone's dashboard (new and optional: older apps ignore it).
        $since = CarbonImmutable::now()->startOfDay()->subDays(29);
        $perDay = Submission::query()
            ->where('author_id', $user->id)
            ->where('status', '<>', Submission::RECEIVING)
            ->where('completed_at', '>=', $since)
            ->selectRaw(AnalyticsController::dayExpression().' AS day, count(*) AS n')
            ->groupByRaw('1')
            ->toBase()->pluck('n', 'day');
        $activity = [];
        for ($day = $since; $day->lessThanOrEqualTo(now()); $day = $day->addDay()) {
            $activity[] = ['date' => $day->toDateString(), 'count' => (int) ($perDay[$day->toDateString()] ?? 0)];
        }

        return response()->json([
            'serverTime' => now()->toIso8601String(),
            'activity' => $activity,
            'settings' => [
                'photoMaxKb' => $settings->collect_photo_max_kb,
                'keepOnDeviceDays' => $settings->collect_keep_on_device_days,
            ],
            'assignments' => $assignments->map(function (Assignment $assignment) use ($progress) {
                $form = $assignment->form;
                $version = $form->currentVersion;

                return [
                    'id' => $assignment->id,
                    'status' => $assignment->status,
                    'canStart' => $assignment->allowsCollecting() && $version !== null && ! $form->isArchived(),
                    'startsOn' => $assignment->starts_on?->toDateString(),
                    'endsOn' => $assignment->ends_on?->toDateString(),
                    // Submissions expected from this person (null: not set). New and optional: older apps ignore it.
                    'expected' => $assignment->expected_submissions,
                    'closesAt' => $assignment->closes_at?->toIso8601String(),
                    'form' => [
                        'id' => $form->id,
                        'name' => $form->name,
                        'description' => $form->description,
                        'purpose' => $form->purpose,
                        'status' => $form->status,
                        'programme' => $form->programme?->label(),
                        'containsPersonalData' => $form->contains_personal_data,
                    ],
                    'unit' => ($assignment->unit ?? $form->unit) === null ? null : [
                        'id' => ($assignment->unit ?? $form->unit)->id,
                        'label' => ($assignment->unit ?? $form->unit)->label(),
                    ],
                    'version' => $version === null ? null : [
                        'id' => $version->id,
                        'number' => $version->number,
                        'sha256' => $version->sha256,
                        'schemaVersion' => (int) ($version->definition['schema_version'] ?? 1),
                        'publishedAt' => $version->published_at->toIso8601String(),
                    ],
                    'progress' => $progress($form->id),
                ];
            })->values(),
        ]);
    }

    /**
     * One published version's definition, for a form the collector is
     * assigned to (current or older, while it still takes submissions).
     */
    public function version(Request $request, string $version): JsonResponse
    {
        $user = self::user($request);
        $model = FormVersion::query()->with('form')->findOrFail($version);
        abort_unless(Assignment::query()->live()->where('form_id', $model->form_id)->where('user_id', $user->id)->exists(), 404);

        $supports = (int) $request->query('supports', '1');
        $schema = (int) ($model->definition['schema_version'] ?? 1);
        if ($schema > $supports) {
            throw new Refused(426, 'app_too_old', 'Update the app to fill in this form.', ['schemaVersion' => $schema]);
        }

        return response()->json([
            'id' => $model->id,
            'formId' => $model->form_id,
            'number' => $model->number,
            'sha256' => $model->sha256,
            'definition' => $model->definition,
            'publishedAt' => $model->published_at->toIso8601String(),
            'accepting' => $model->acceptsSubmissions(),
            'acceptUntil' => $model->accept_until?->toIso8601String(),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'form_version_id' => ['required', 'uuid'],
            'programme_unit_id' => ['nullable', 'uuid'],
            'answers' => ['present', 'array'],
            'consent_given' => ['nullable', 'boolean'],
            'started_at' => ['nullable', 'string', 'max:40'],
            'completed_at' => ['required', 'string', 'max:40'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'attachments' => ['sometimes', 'array', 'max:100'],
            'attachments.*.question' => ['required', 'string', 'max:64'],
            'attachments.*.sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'attachments.*.size' => ['required', 'integer', 'min:1'],
            'attachments.*.mime' => ['required', 'string', Rule::in(array_keys(Submissions::PHOTO_TYPES))],
        ]);
        $data['id'] = strtolower($data['id']);
        // Answers keep the types the phone sent (numbers stay numbers).
        $data['answers'] = (array) $request->json('answers', []);

        ['submission' => $submission, 'replayed' => $replayed] = $this->submissions->receive($data, self::user($request));

        return response()->json([...$this->state($submission), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    /**
     * One photo, as the raw request body (or a multipart "file").
     */
    public function attach(Request $request, string $submission, string $question): JsonResponse
    {
        $model = $this->own($request, $submission);
        $file = $request->file('file');
        $bytes = $file !== null && ! is_array($file) ? (string) file_get_contents($file->getRealPath()) : $request->getContent();
        abort_if($bytes === '', 422, 'The photo is empty.');

        return response()->json($this->state($this->submissions->attach($model, $question, $bytes, self::user($request))));
    }

    public function show(Request $request, string $submission): JsonResponse
    {
        $model = $this->own($request, $submission);

        return response()->json($this->state($model, withCorrection: $model->status === Submission::CORRECTION_REQUESTED));
    }

    /**
     * The collector's submissions changed since a moment: the phone's
     * incremental status sync. Ordered by (updatedAt, id); pass back the
     * last row's as since/after for the next page.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['since' => ['nullable', 'date'], 'after' => ['nullable', 'uuid'], 'limit' => ['nullable', 'integer', 'min:1', 'max:200']]);
        $limit = (int) ($data['limit'] ?? 100);
        $since = isset($data['since']) ? CarbonImmutable::parse($data['since']) : null;

        $rows = Submission::query()->where('author_id', self::user($request)->id)
            ->when($since !== null, fn ($query) => $query->where(fn ($q) => $q->where('updated_at', '>', $since)
                ->orWhere(fn ($same) => $same->where('updated_at', $since)->where('id', '>', $data['after'] ?? ''))))
            ->with('currentRevision')
            ->orderBy('updated_at')->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'data' => $rows->map(fn (Submission $row) => [
                'id' => $row->id,
                'status' => $row->status,
                'revision' => $row->currentRevision->number ?? 0,
                'updatedAt' => $row->updated_at?->toIso8601String(),
            ])->values(),
            'more' => $more,
        ]);
    }

    /**
     * The collector's correction, asked for by a reviewer.
     */
    public function correct(Request $request, string $submission): JsonResponse
    {
        $model = $this->own($request, $submission);
        $data = $request->validate([
            'base_revision' => ['required', 'integer', 'min:0'],
            'answers' => ['present', 'array'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        ['submission' => $corrected, 'replayed' => $replayed] = $this->submissions->correct($model, (array) $request->json('answers', []), $data['reason'], (int) $data['base_revision'], self::user($request), asReviewer: false);

        return response()->json([...$this->state($corrected), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Submission $submission, bool $withCorrection = false): array
    {
        $submission->loadMissing(['currentRevision', 'version']);
        $state = [
            'id' => $submission->id,
            'status' => $submission->status,
            'receivedAt' => $submission->received_at->toIso8601String(),
            'missing' => Submissions::missing($submission),
            'revision' => $submission->currentRevision->number ?? 0,
        ];

        if ($withCorrection) {
            $request = ReviewDecision::query()->where('submission_id', $submission->id)->where('action', 'correction_requested')->latest('created_at')->first();
            $definition = $submission->version->definition;
            $state['correction'] = [
                'comment' => $request?->comment,
                'notes' => $request->notes ?? (object) [],
                // The collector's own answers, to correct on the phone.
                'answers' => $submission->currentAnswers(),
                'formVersionId' => $submission->form_version_id,
                'labels' => collect(Definition::questions($definition))->map(fn (array $q, string $name) => is_string($q['label'] ?? null) ? $q['label'] : $name),
            ];
        }

        return $state;
    }

    private function own(Request $request, string $id): Submission
    {
        abort_unless(preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1, 404);

        return Submission::query()->where('author_id', self::user($request)->id)->findOrFail(strtolower($id));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
