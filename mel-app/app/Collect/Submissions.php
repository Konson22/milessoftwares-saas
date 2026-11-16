<?php

namespace App\Collect;

use App\Collect\Models\Assignment;
use App\Collect\Models\Attachment;
use App\Collect\Models\Form;
use App\Collect\Models\FormVersion;
use App\Collect\Models\ReviewDecision;
use App\Collect\Models\Submission;
use App\Collect\Models\SubmissionRevision;
use App\Mel\AuditTrail;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\Settings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Collected submissions arriving from the field (ADR-0012 §9, ADR-0019 §21).
 *
 * The phone makes the submission's id, so sending it again is the same
 * submission: the same id with the same content answers "replayed"; the
 * same id with other content is refused as a conflict and nothing changes.
 * A request whose reply was lost is therefore always safe to send again.
 *
 * What is checked, on the server, every time:
 * - the person is assigned to the form, and the assignment still takes
 *   submissions (closing assignments for a while, revoked ones never);
 * - the version is one of the form's published versions and still accepted;
 * - the answers pass the same rules the phone ran (Rules), on the day the
 *   form was completed;
 * - consent, when the form asks for it;
 * - every photo answer is listed in the file manifest, and nothing else is.
 *
 * Photos follow one by one (attach). The submission is "receiving" until
 * the last one arrives, then "submitted" in the same transaction.
 */
class Submissions
{
    public const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** A photo is never larger than this, whatever the workspace setting. */
    public const PHOTO_HARD_LIMIT = 10 * 1024 * 1024;

    /** A device clock may run this far ahead of the server's. */
    private const CLOCK_SKEW_HOURS = 24;

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Takes a submission from the field, or recognizes it as already taken.
     *
     * @param  array{id: string, form_version_id: string, programme_unit_id?: string|null, answers: array<string, mixed>, consent_given?: bool|null, started_at?: string|null, completed_at: string, app_version?: string|null, attachments?: list<array{question: string, sha256: string, size: int, mime: string}>}  $payload
     * @return array{submission: Submission, replayed: bool}
     */
    public function receive(array $payload, User $author): array
    {
        $hash = self::contentHash($payload);

        $existing = Submission::query()->find($payload['id']);
        if ($existing !== null) {
            return ['submission' => $this->replay($existing, $hash, $author), 'replayed' => true];
        }

        $version = FormVersion::query()->with('form')->find($payload['form_version_id']);
        if ($version === null) {
            throw new Refused(404, 'form_not_found', 'This form is not available to you.');
        }
        $form = $version->form;
        $assignment = $this->assignmentFor($form, $author);
        $definition = $version->definition;

        if (! $form->acceptsSubmissions()) {
            throw new Refused(409, 'form_archived', 'This form was archived and no longer takes submissions.');
        }
        if (! $version->acceptsSubmissions()) {
            throw new Refused(409, 'version_closed', "Version {$version->number} of this form no longer takes submissions. Ask your coordinator to extend it.");
        }

        $completedAt = self::moment($payload['completed_at'], 'completed_at');
        if ($completedAt->greaterThan(CarbonImmutable::now()->addHours(self::CLOCK_SKEW_HOURS))) {
            throw Refused::invalid('The form says it was completed in the future. Check the phone\'s date and time.');
        }
        $startedAt = isset($payload['started_at']) ? self::moment($payload['started_at'], 'started_at') : null;
        if ($startedAt !== null && $startedAt->greaterThan($completedAt)) {
            $startedAt = null;
        }

        $consent = $payload['consent_given'] ?? null;
        if (self::asksConsent($definition) && $consent !== true) {
            throw new Refused(422, 'consent_required', 'This form needs the respondent\'s consent before any answer is kept.');
        }

        // "Today" for the rules is the day the form was filled in, where it was filled in.
        $evaluation = Rules::evaluate($definition, $payload['answers'], $completedAt->toDateString());
        if ($evaluation['errors'] !== []) {
            throw Refused::invalid('Some answers are missing or not valid.', $evaluation['errors']);
        }
        $answers = $evaluation['answers'];
        $manifest = $this->manifest($definition, $answers, $payload['attachments'] ?? []);
        $unitId = $this->unitFor($form, $assignment, $payload['programme_unit_id'] ?? null);
        $place = self::firstPlace($definition, $answers);

        try {
            $submission = DB::transaction(function () use ($payload, $author, $form, $version, $unitId, $startedAt, $completedAt, $answers, $hash, $manifest, $consent, $place) {
                $submission = new Submission;
                $submission->forceFill([
                    'id' => $payload['id'],
                    'form_id' => $form->id,
                    'form_version_id' => $version->id,
                    'programme_id' => $form->programme_id,
                    'programme_unit_id' => $unitId,
                    'author_id' => $author->id,
                    'status' => $manifest === [] ? Submission::SUBMITTED : Submission::RECEIVING,
                    'source' => 'mobile',
                    'started_at' => $startedAt,
                    'completed_at' => $completedAt,
                    'received_at' => now(),
                    'answers' => $answers,
                    'content_hash' => $hash,
                    'attachments_manifest' => $manifest,
                    'attachments_expected' => count($manifest),
                    'attachments_received' => 0,
                    'consent_given' => $consent,
                    'lat' => $place['lat'] ?? null,
                    'lng' => $place['lng'] ?? null,
                    'accuracy' => $place['accuracy'] ?? null,
                    'app_version' => isset($payload['app_version']) ? mb_substr($payload['app_version'], 0, 32) : null,
                ])->save();

                if ($manifest === []) {
                    $this->decide($submission, 'received', $author);
                }

                return $submission;
            });
        } catch (QueryException $e) {
            // The same id arrived twice at once, or is another workspace's.
            $existing = Submission::query()->find($payload['id']);
            if ($existing === null) {
                if (self::isUniqueViolation($e)) {
                    throw new Refused(409, 'id_conflict', 'This submission id is already in use. Contact support with the submission id.');
                }
                throw $e;
            }

            return ['submission' => $this->replay($existing, $hash, $author), 'replayed' => true];
        }

        return ['submission' => $submission, 'replayed' => false];
    }

    /**
     * Stores one photo of a submission. Sending a file already stored is a
     * no-op; the last expected file makes the submission "submitted".
     */
    public function attach(Submission $submission, string $question, string $bytes, User $author): Submission
    {
        if ($submission->author_id !== $author->id) {
            throw new Refused(404, 'not_found', 'This submission is not yours to send.');
        }

        $expected = collect($submission->attachments_manifest)->firstWhere('question', $question);
        if ($expected === null) {
            throw new Refused(422, 'unexpected_file', "No photo is expected for \"{$question}\".");
        }

        $sha256 = hash('sha256', $bytes);
        $existing = Attachment::query()->where('submission_id', $submission->id)->where('question', $question)->first();
        if ($existing !== null) {
            if ($existing->sha256 !== $sha256) {
                throw new Refused(409, 'file_conflict', "A different photo was already received for \"{$question}\".");
            }

            return $submission->fresh() ?? $submission;
        }

        if ($sha256 !== $expected['sha256'] || strlen($bytes) !== (int) $expected['size']) {
            throw new Refused(422, 'file_mismatch', 'The photo does not match what the submission listed. It may have been damaged on the way: send it again.');
        }
        $mime = self::sniff($bytes);
        if ($mime === null || $mime !== $expected['mime']) {
            throw new Refused(422, 'file_type', 'Only JPEG, PNG or WebP photos are accepted.');
        }

        $path = 'collect/'.$submission->organization_id.'/'.$submission->id.'/'.$question.'-'.substr($sha256, 0, 16).'.'.self::PHOTO_TYPES[$mime];
        // Written before the row: a retry after a failure overwrites the same path.
        Storage::disk('local')->put($path, $bytes);

        return DB::transaction(function () use ($submission, $question, $sha256, $bytes, $mime, $path, $author) {
            /** @var Submission $locked */
            $locked = Submission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if (Attachment::query()->where('submission_id', $locked->id)->where('question', $question)->exists()) {
                return $locked;
            }

            Attachment::query()->create([
                'submission_id' => $locked->id,
                'question' => $question,
                'sha256' => $sha256,
                'size' => strlen($bytes),
                'mime_type' => $mime,
                'path' => $path,
                'received_at' => now(),
            ]);

            $received = $locked->attachments_received + 1;
            $complete = $received >= $locked->attachments_expected;
            $locked->forceFill([
                'attachments_received' => $received,
                'status' => $complete && $locked->status === Submission::RECEIVING ? Submission::SUBMITTED : $locked->status,
            ])->save();

            if ($complete && $locked->wasChanged('status')) {
                $this->decide($locked, 'received', $author);
            }

            return $locked;
        });
    }

    /**
     * The photos the server still waits for.
     *
     * @return list<string>
     */
    public static function missing(Submission $submission): array
    {
        $have = Attachment::query()->where('submission_id', $submission->id)->pluck('question')->all();

        return array_values(array_diff(array_column($submission->attachments_manifest, 'question'), $have));
    }

    /**
     * A correction: the full set of answers as they should be, on the
     * submission's own version, with a reason. It becomes a new revision;
     * the original answers never change.
     *
     * $baseRevision is the revision the corrector started from (0: the
     * original). When someone else corrected it meanwhile, the correction is
     * refused as a conflict instead of overwriting theirs; the same
     * correction sent again is recognized and not made twice.
     *
     * @param  array<string, mixed>  $answers
     * @return array{submission: Submission, replayed: bool}
     */
    public function correct(Submission $submission, array $answers, string $reason, int $baseRevision, User $user, bool $asReviewer): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw Refused::invalid('Say why the answers are corrected.');
        }

        return DB::transaction(function () use ($submission, $answers, $reason, $baseRevision, $user, $asReviewer) {
            /** @var Submission $locked */
            $locked = Submission::query()->with(['version', 'currentRevision'])->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $current = $locked->currentRevision->number ?? 0;

            $evaluation = Rules::evaluate($locked->version->definition, $answers, $locked->completed_at->toDateString());

            if ($baseRevision !== $current) {
                // The same correction, sent again after its reply was lost.
                if ($baseRevision === $current - 1 && $locked->currentRevision !== null && $locked->currentRevision->author_id === $user->id
                    && FormVersion::hash($locked->currentRevision->answers) === FormVersion::hash($evaluation['answers'])) {
                    return ['submission' => $locked, 'replayed' => true];
                }
                throw new Refused(409, 'revision_conflict', 'Someone corrected this submission meanwhile. Reload it and make your correction again.', ['revision' => $current]);
            }

            $allowed = $asReviewer
                ? in_array($locked->status, [Submission::SUBMITTED, Submission::UNDER_REVIEW, Submission::CORRECTION_REQUESTED], true)
                : $locked->status === Submission::CORRECTION_REQUESTED && $locked->author_id === $user->id;
            if (! $allowed) {
                throw new Refused(409, 'not_correctable', match ($locked->status) {
                    Submission::ACCEPTED, Submission::REJECTED => 'This submission was decided. A reviewer must reopen it before it can be corrected.',
                    Submission::RECEIVING => 'This submission is still receiving its photos.',
                    default => 'No correction was asked for this submission.',
                });
            }

            if ($evaluation['errors'] !== []) {
                throw Refused::invalid('Some answers are missing or not valid.', $evaluation['errors']);
            }
            $before = $locked->currentAnswers();
            $after = $evaluation['answers'];

            foreach (Definition::questions($locked->version->definition) as $name => $question) {
                if (($question['type'] ?? null) === 'photo' && ($before[$name] ?? null) !== ($after[$name] ?? null)) {
                    throw Refused::invalid('Photos cannot be changed in a correction.', [$name => 'photo_locked']);
                }
            }

            $changed = self::changed($before, $after);
            if ($changed === []) {
                throw Refused::invalid('Nothing was changed.');
            }

            $revision = SubmissionRevision::query()->create([
                'submission_id' => $locked->id,
                'number' => $current + 1,
                'answers' => $after,
                'changed' => $changed,
                'reason' => mb_substr($reason, 0, 5000),
                'author_id' => $user->id,
                'created_at' => now(),
            ]);

            $locked->forceFill([
                'current_revision_id' => $revision->id,
                'status' => $locked->status === Submission::CORRECTION_REQUESTED ? Submission::SUBMITTED : $locked->status,
            ])->save();
            $locked->setRelation('currentRevision', $revision);

            $this->decide($locked, 'corrected', $user, $reason, ['changed' => $changed]);

            return ['submission' => $locked, 'replayed' => false];
        });
    }

    /**
     * Records a review step and audits it, in the caller's transaction.
     *
     * @param  array<string, mixed>|null  $notes
     */
    public function decide(Submission $submission, string $action, User $user, ?string $comment = null, ?array $notes = null): void
    {
        ReviewDecision::query()->create([
            'submission_id' => $submission->id,
            'action' => $action,
            'comment' => $comment,
            'notes' => $notes,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
        $this->audit->record('collected.'.$action, $submission->loadMissing('form'), $user, array_filter([
            'comment' => $comment === null ? null : mb_substr($comment, 0, 500),
        ]));
    }

    /**
     * The hash that tells a resend from a different submission: what was
     * collected, not the app version that sent it.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function contentHash(array $payload): string
    {
        $files = $payload['attachments'] ?? [];
        usort($files, fn (array $a, array $b) => strcmp((string) $a['question'], (string) $b['question']));

        return FormVersion::hash([
            'form_version_id' => $payload['form_version_id'],
            'programme_unit_id' => $payload['programme_unit_id'] ?? null,
            'answers' => $payload['answers'],
            'consent_given' => $payload['consent_given'] ?? null,
            'started_at' => isset($payload['started_at']) ? self::moment($payload['started_at'], 'started_at')->utc()->toIso8601String() : null,
            'completed_at' => self::moment($payload['completed_at'], 'completed_at')->utc()->toIso8601String(),
            'attachments' => array_map(fn (array $file) => [
                'question' => (string) $file['question'],
                'sha256' => strtolower((string) $file['sha256']),
                'size' => (int) $file['size'],
                'mime' => (string) $file['mime'],
            ], $files),
        ]);
    }

    /**
     * Whether the form asks for consent before the first question.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function asksConsent(array $definition): bool
    {
        $consent = $definition['settings']['consent'] ?? null;

        return is_array($consent) && is_string($consent['text'] ?? null) && trim($consent['text']) !== '' && ($consent['required'] ?? true) !== false;
    }

    /**
     * Names whose answers differ.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function changed(array $before, array $after): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $name) {
            if (FormVersion::hash(['v' => $before[$name] ?? null]) !== FormVersion::hash(['v' => $after[$name] ?? null])) {
                $changed[] = (string) $name;
            }
        }

        return $changed;
    }

    private function replay(Submission $existing, string $hash, User $author): Submission
    {
        if ($existing->author_id !== $author->id || ! hash_equals($existing->content_hash, $hash)) {
            throw new Refused(409, 'id_conflict', 'A different submission was already received with this id. Nothing was changed.');
        }

        return $existing;
    }

    /**
     * The collector's assignment that lets this submission in: the server
     * decides by its own clock and the assignment as it stands.
     */
    private function assignmentFor(Form $form, User $author): Assignment
    {
        $assignment = Assignment::query()->where('form_id', $form->id)->where('user_id', $author->id)
            ->orderByRaw("case when status = 'revoked' then 1 else 0 end")->orderByDesc('updated_at')->first();

        if ($assignment === null) {
            throw new Refused(404, 'form_not_found', 'This form is not available to you.');
        }
        if ($assignment->status === Assignment::REVOKED) {
            throw new Refused(403, 'assignment_revoked', 'Your assignment to this form was revoked. Your coordinator can reopen it; this submission stays on your phone.');
        }
        if (! $assignment->acceptsSubmissions()) {
            throw new Refused(403, 'assignment_closed', 'Your assignment to this form has ended. Your coordinator can extend it; this submission stays on your phone.');
        }

        return $assignment;
    }

    private function unitFor(Form $form, Assignment $assignment, ?string $requested): ?string
    {
        $fixed = $form->programme_unit_id ?? $assignment->programme_unit_id;

        if ($requested === null || $requested === $fixed) {
            return $fixed;
        }
        if ($fixed !== null || $form->programme_id === null
            || ! ProgrammeUnit::query()->whereKey($requested)->where('programme_id', $form->programme_id)->whereNull('archived_at')->exists()) {
            throw Refused::invalid('Choose a unit of this form\'s programme.');
        }

        return $requested;
    }

    /**
     * The file manifest, checked against the photo answers.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     * @param  list<array{question: string, sha256: string, size: int, mime: string}>  $files
     * @return list<array{question: string, sha256: string, size: int, mime: string}>
     */
    private function manifest(array $definition, array $answers, array $files): array
    {
        $limit = min(self::PHOTO_HARD_LIMIT, max(1, Settings::current()->collect_photo_max_kb) * 1024 * 2);
        $photos = [];
        foreach (Definition::questions($definition) as $name => $question) {
            if (($question['type'] ?? null) === 'photo' && isset($answers[$name])) {
                $photos[$name] = $answers[$name];
            }
        }

        $manifest = [];
        foreach ($files as $file) {
            $question = (string) $file['question'];
            $sha256 = strtolower((string) $file['sha256']);
            if (! array_key_exists($question, $photos) || isset($manifest[$question]) || $photos[$question] !== $sha256) {
                throw Refused::invalid('The photo list does not match the photo answers.', [$question => 'attachment']);
            }
            if (! isset(self::PHOTO_TYPES[$file['mime']]) || (int) $file['size'] < 1 || (int) $file['size'] > $limit) {
                throw Refused::invalid('A photo is too large or not a JPEG, PNG or WebP image.', [$question => 'attachment']);
            }
            $manifest[$question] = ['question' => $question, 'sha256' => $sha256, 'size' => (int) $file['size'], 'mime' => (string) $file['mime']];
        }

        foreach (array_keys($photos) as $name) {
            if (! isset($manifest[$name])) {
                throw Refused::invalid('A photo answer has no file listed.', [$name => 'attachment']);
            }
        }

        return array_values($manifest);
    }

    /**
     * The first geopoint answered: where the form was filled in.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     * @return array{lat: float, lng: float, accuracy: float|null}|null
     */
    private static function firstPlace(array $definition, array $answers): ?array
    {
        foreach (Definition::questions($definition) as $name => $question) {
            if (($question['type'] ?? null) === 'geopoint' && is_array($answers[$name] ?? null)) {
                return [
                    'lat' => round((float) $answers[$name]['lat'], 7),
                    'lng' => round((float) $answers[$name]['lng'], 7),
                    'accuracy' => isset($answers[$name]['accuracy']) ? round((float) $answers[$name]['accuracy'], 2) : null,
                ];
            }
        }

        return null;
    }

    private static function moment(string $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw Refused::invalid("The {$field} time is not a valid date and time.");
        }
    }

    /**
     * The image type from the bytes themselves, never from what was claimed.
     */
    public static function sniff(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? $info['mime'] : null;

        return is_string($mime) && isset(self::PHOTO_TYPES[$mime]) ? $mime : null;
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();

        return $code === '23505' || $code === '23000' || str_contains($e->getMessage(), 'UNIQUE');
    }
}
