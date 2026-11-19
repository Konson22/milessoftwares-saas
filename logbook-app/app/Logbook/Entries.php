<?php

namespace App\Logbook;

use App\Logbook\Models\Attachment;
use App\Logbook\Models\Entry;
use App\Logbook\Models\EntryReview;
use App\Logbook\Models\EntryRevision;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Models\User;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entries and their lifecycle (ADR-0025 §2.6), every transition checked
 * here with the entry row locked:
 *
 * - draft, returned: editable by their author only;
 * - submit: values checked in full against the entry's template version,
 *   a revision kept, a reference drawn the first time; submitted when the
 *   sheet requires review, finalized when it does not;
 * - review: submitted only, never by the author; returning needs a comment.
 *
 * Finalized and reviewed entries never change.
 */
final class Entries
{
    /**
     * A new entry, saved as a draft or submitted. Repeating a request with
     * the same idempotency key returns the entry the first one made.
     *
     * $version is the template version the entry was written on: the
     * sheet's current one by default. The phone app, which may have written
     * offline on a version since replaced, names it; any version the sheet
     * has published is accepted, since published versions never change.
     *
     * @param  array<string, mixed>  $input
     */
    public static function create(LogSheet $sheet, User $by, array $input, string $occurredAt, ?string $key, bool $submit, ?LogSheetVersion $version = null): Entry
    {
        if ($key !== null && ($existing = self::byKey($by, $key)) !== null) {
            return $existing;
        }

        self::open($sheet);
        if ($version !== null && ($version->log_sheet_id !== $sheet->id || $version->status !== 'published')) {
            throw ValidationException::withMessages(['version_id' => 'This template version is not published on this log sheet.']);
        }
        $version ??= $sheet->publishedVersion;
        assert($version instanceof LogSheetVersion);
        // Submitting at once: everything is checked first, so a refusal leaves nothing behind.
        $values = self::clean($version, $input, [], requireAll: $submit);

        try {
            return DB::transaction(function () use ($sheet, $version, $by, $values, $occurredAt, $key, $submit) {
                $entry = Entry::query()->create([
                    'logbook_id' => $sheet->logbook_id,
                    'log_sheet_id' => $sheet->id,
                    'log_sheet_version_id' => $version->id,
                    'status' => 'draft',
                    'occurred_at' => CarbonImmutable::parse($occurredAt),
                    'values' => $values,
                    'created_by' => $by->id,
                    'idempotency_key' => $key,
                ]);
                AuditTrail::record('entry.created', $entry, $by, ['version' => $version->version]);

                return $submit ? self::submit($entry, $by) : $entry;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same key raced in at the same moment: the first one won.
            $existing = $key !== null ? self::byKey($by, $key) : null;
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * Saves a draft or returned entry's values (not its files, which are
     * added and removed one by one).
     *
     * @param  array<string, mixed>  $input
     */
    public static function update(Entry $entry, User $by, array $input, string $occurredAt): Entry
    {
        return DB::transaction(function () use ($entry, $by, $input, $occurredAt) {
            $entry = self::lockForAuthor($entry, $by);
            $values = self::clean($entry->version, $input, self::files($entry), requireAll: false);
            $entry->forceFill(['values' => $values, 'occurred_at' => CarbonImmutable::parse($occurredAt)])->save();

            return $entry;
        });
    }

    public static function submit(Entry $entry, User $by): Entry
    {
        return DB::transaction(function () use ($entry, $by) {
            $entry = self::lockForAuthor($entry, $by);
            $sheet = $entry->sheet;
            self::open($sheet);
            if (! Access::reachesSheet($sheet, 'contribute')) {
                abort(403, 'You can no longer write entries on this sheet.');
            }

            $version = $entry->version;
            $values = self::clean($version, $entry->values, self::files($entry), requireAll: true);
            $first = $entry->revision === 0;
            $reference = $entry->reference ?? References::next($sheet->code);
            $revision = $entry->revision + 1;
            $reviewRequired = $first ? $sheet->review_required : $entry->review_required;

            $entry->forceFill([
                'reference' => $reference,
                'values' => $values,
                'revision' => $revision,
                'review_required' => $reviewRequired,
                'status' => $reviewRequired ? 'submitted' : 'finalized',
                'submitted_at' => now(),
                'search_text' => Fields::searchText($reference, $version->fields, $values, self::people($version->fields, $values)),
            ])->save();

            EntryRevision::query()->create([
                'log_entry_id' => $entry->id,
                'revision' => $revision,
                'log_sheet_version_id' => $version->id,
                'occurred_at' => $entry->occurred_at,
                'values' => $values,
                'submitted_by' => $by->id,
                'submitted_at' => now(),
            ]);

            AuditTrail::record($first ? 'entry.submitted' : 'entry.resubmitted', $entry, $by, [
                'revision' => $revision,
                'status' => $entry->status,
            ]);

            return $entry;
        });
    }

    /**
     * Marks a submitted entry reviewed, or returns it for correction.
     */
    public static function review(Entry $entry, User $by, string $decision, ?string $comment): Entry
    {
        return DB::transaction(function () use ($entry, $by, $decision, $comment) {
            $entry = Entry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($entry->status !== 'submitted') {
                throw ValidationException::withMessages(['decision' => 'Only submitted entries are reviewed. This one is '.$entry->status.'.']);
            }
            if ($entry->created_by === $by->id) {
                throw ValidationException::withMessages(['decision' => 'Nobody reviews their own entry.']);
            }
            if (! Access::reachesSheet($entry->sheet, 'review')) {
                abort(403, 'You do not review this log sheet.');
            }
            $comment = $comment !== null ? trim($comment) : null;
            if ($decision === 'returned' && ($comment === null || $comment === '')) {
                throw ValidationException::withMessages(['comment' => 'Say what needs correcting.']);
            }

            EntryReview::query()->create([
                'log_entry_id' => $entry->id,
                'revision' => $entry->revision,
                'decision' => $decision,
                'comment' => $comment ?: null,
                'reviewer_id' => $by->id,
                'reviewer_name' => $by->name,
                'decided_at' => now(),
            ]);

            $entry->forceFill($decision === 'reviewed'
                ? ['status' => 'reviewed', 'reviewed_at' => now(), 'reviewed_by' => $by->id]
                : ['status' => 'returned'])->save();

            AuditTrail::record($decision === 'reviewed' ? 'entry.reviewed' : 'entry.returned', $entry, $by, ['revision' => $entry->revision]);

            return $entry;
        });
    }

    /**
     * Discards a draft that was never submitted, with its files.
     */
    public static function discard(Entry $entry, User $by): void
    {
        $paths = DB::transaction(function () use ($entry, $by) {
            $entry = self::lockForAuthor($entry, $by);
            if ($entry->status !== 'draft' || $entry->revision > 0) {
                throw ValidationException::withMessages(['entry' => 'Only a draft that was never submitted can be discarded.']);
            }
            $paths = array_values(array_map(strval(...), $entry->attachments()->pluck('path')->all()));
            AuditTrail::record('entry.discarded', $entry, $by);
            $entry->attachments()->delete();
            $entry->delete();

            return $paths;
        });

        Attachments::deleteFiles($paths);
    }

    /**
     * The entry, locked, when $by is its author and it may still change.
     */
    public static function lockForAuthor(Entry $entry, User $by): Entry
    {
        $entry = Entry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
        if ($entry->created_by !== $by->id) {
            abort(403, 'Only the author changes an entry.');
        }
        if (! $entry->editable()) {
            throw ValidationException::withMessages(['entry' => 'This entry is '.$entry->status.' and can no longer be changed.']);
        }

        return $entry;
    }

    /**
     * The entry's live attachment ids, by field.
     *
     * @return array<string, list<string>>
     */
    public static function files(Entry $entry): array
    {
        $out = [];
        foreach ($entry->attachments()->whereNull('removed_at')->get(['id', 'field']) as $file) {
            /** @var Attachment $file */
            $out[$file->field][] = $file->id;
        }

        return $out;
    }

    /**
     * Names of people referenced in values, by id.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function people(array $fields, array $values): array
    {
        $ids = [];
        foreach ($fields as $field) {
            if ($field['type'] === 'user' && is_string($values[$field['name']] ?? null)) {
                $ids[] = $values[$field['name']];
            }
        }

        return $ids === [] ? [] : User::query()->whereIn('id', array_unique($ids))->pluck('name', 'id')->all();
    }

    private static function open(LogSheet $sheet): void
    {
        if (! $sheet->open()) {
            throw ValidationException::withMessages(['entry' => 'This log sheet is not accepting entries (unpublished or archived).']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $files
     * @return array<string, mixed>
     */
    private static function clean(LogSheetVersion $version, array $input, array $files, bool $requireAll): array
    {
        $result = Fields::clean($version->fields, $input, $files, fn (string $id) => WorkspaceMember::query()->ofUser($id)->exists(), $requireAll);

        if ($result['errors'] !== []) {
            throw ValidationException::withMessages(collect($result['errors'])->mapWithKeys(fn ($m, $k) => ["values.{$k}" => $m])->all());
        }

        return $result['values'];
    }

    public static function byKey(User $by, string $key): ?Entry
    {
        return Entry::query()->where('created_by', $by->id)->where('idempotency_key', $key)->first();
    }
}
