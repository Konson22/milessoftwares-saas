<?php

namespace App\Logbook;

use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Log sheets and their templates. A sheet has at most one draft version;
 * publishing it makes an immutable version that new entries use. Entries
 * already written keep the version they were written against.
 */
final class Sheets
{
    /**
     * @param  array<string, mixed>  $settings  name, code, description, instructions, review_required
     * @param  list<array<string, mixed>>  $fields
     */
    public static function create(Logbook $logbook, array $settings, array $fields, User $by, ?string $source = null): LogSheet
    {
        abort_unless($logbook->isActive(), 409, 'This logbook is archived.');
        $checked = Fields::check($fields);
        self::fail($checked['errors']);

        return DB::transaction(function () use ($logbook, $settings, $checked, $by, $source) {
            $sheet = $logbook->sheets()->create([
                ...$settings,
                'code' => strtoupper((string) $settings['code']),
                'review_required' => (bool) ($settings['review_required'] ?? false),
                'status' => 'draft',
                'created_by' => $by->id,
            ]);
            $sheet->versions()->create(['version' => 1, 'status' => 'draft', 'fields' => $checked['fields'], 'created_by' => $by->id]);
            AuditTrail::record('sheet.created', $sheet, $by, ['from' => $source]);

            return $sheet;
        });
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function updateSettings(LogSheet $sheet, array $settings, User $by): void
    {
        DB::transaction(function () use ($sheet, $settings, $by) {
            if (isset($settings['code'])) {
                $settings['code'] = strtoupper($settings['code']);
            }
            $sheet->fill($settings);
            $changed = array_keys($sheet->getDirty());
            $sheet->save();
            if ($changed !== []) {
                AuditTrail::record('sheet.updated', $sheet, $by, ['changed' => $changed]);
            }
        });
    }

    /**
     * Saves fields to the sheet's draft, opening one (from the published
     * version) when there is none.
     */
    /**
     * @param  bool  $withLocation  false: the draft keeps its location settings (ADR-0028)
     * @param  bool  $withRecording  false: the draft keeps its recording mode (ADR-0030)
     */
    public static function saveDraft(LogSheet $sheet, mixed $fields, User $by, mixed $location = null, bool $withLocation = false, mixed $recording = null, bool $withRecording = false): LogSheetVersion
    {
        self::editable($sheet);
        $checked = Fields::check($fields);
        self::fail($checked['errors']);
        $settings = $withLocation ? Tracks::settingsInput($location) : null;
        $mode = $withRecording ? Recording::settingsInput($recording) : null;

        return DB::transaction(function () use ($sheet, $checked, $by, $settings, $withLocation, $mode, $withRecording) {
            $draft = self::draft($sheet, $by);
            $draft->forceFill(['fields' => $checked['fields']]);
            if ($withLocation) {
                $draft->forceFill(['location' => $settings]);
            }
            if ($withRecording) {
                $draft->forceFill(['recording' => $mode]);
            }
            $draft->save();

            return $draft;
        });
    }

    /**
     * The sheet's draft version, opened from the latest published one when
     * there is none.
     */
    public static function draft(LogSheet $sheet, User $by): LogSheetVersion
    {
        $draft = $sheet->draftVersion()->lockForUpdate()->first();
        if ($draft !== null) {
            return $draft;
        }

        $latest = (int) $sheet->versions()->max('version');
        $draft = $sheet->versions()->create([
            'version' => $latest + 1,
            'status' => 'draft',
            'fields' => $sheet->publishedVersion->fields ?? [],
            'location' => $sheet->publishedVersion?->location,
            'recording' => $sheet->publishedVersion?->recording,
            'created_by' => $by->id,
        ]);
        AuditTrail::record('template.draft_opened', $sheet, $by, ['version' => $draft->version]);

        return $draft;
    }

    public static function publish(LogSheet $sheet, User $by): LogSheetVersion
    {
        self::editable($sheet);

        return DB::transaction(function () use ($sheet, $by) {
            $draft = $sheet->draftVersion()->lockForUpdate()->first();
            if ($draft === null) {
                throw ValidationException::withMessages(['fields' => 'There are no changes to publish.']);
            }
            $checked = Fields::check($draft->fields, forPublishing: true);
            self::fail($checked['errors']);
            self::keepsNamesTyped($sheet, $checked['fields']);
            Recording::checkPublish($sheet, $draft);

            $draft->forceFill([
                'fields' => $checked['fields'],
                'status' => 'published',
                'published_at' => now(),
                'published_by' => $by->id,
            ])->save();
            $sheet->forceFill(['published_version_id' => $draft->id, 'status' => 'active'])->save();
            AuditTrail::record('template.published', $sheet, $by, ['version' => $draft->version, 'fields' => count($checked['fields']), 'location' => Tracks::settings($draft)['mode'] ?? null, 'recording' => Recording::settings($draft)['mode'] ?? null]);

            return $draft;
        });
    }

    public static function discardDraft(LogSheet $sheet, User $by): void
    {
        DB::transaction(function () use ($sheet, $by) {
            $draft = $sheet->draftVersion()->lockForUpdate()->first();
            if ($draft === null) {
                return;
            }
            if ($sheet->published_version_id === null) {
                throw ValidationException::withMessages(['fields' => 'A sheet that was never published keeps its draft. Archive the sheet instead.']);
            }
            AuditTrail::record('template.draft_discarded', $sheet, $by, ['version' => $draft->version]);
            $draft->delete();
        });
    }

    /**
     * A new sheet in the same (or another) logbook, starting from this
     * sheet's latest fields as a draft. Entries are never copied.
     */
    public static function duplicate(LogSheet $sheet, Logbook $into, string $name, string $code, User $by): LogSheet
    {
        $fields = $sheet->draftVersion->fields ?? $sheet->publishedVersion->fields ?? [];
        $recording = ($sheet->draftVersion ?? $sheet->publishedVersion)?->recording;

        $copy = self::create($into, [
            'name' => $name,
            'code' => $code,
            'description' => $sheet->description,
            'instructions' => $sheet->instructions,
            'review_required' => $sheet->review_required,
        ], $fields, $by, 'copy of '.$sheet->code);
        // How it is recorded comes along; its schedules do not.
        if ($recording !== null) {
            $copy->draftVersion()->update(['recording' => json_encode($recording)]);
        }

        return $copy;
    }

    public static function archive(LogSheet $sheet, User $by): void
    {
        DB::transaction(function () use ($sheet, $by) {
            $sheet->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
            AuditTrail::record('sheet.archived', $sheet, $by);
        });
    }

    public static function restore(LogSheet $sheet, User $by): void
    {
        DB::transaction(function () use ($sheet, $by) {
            $sheet->forceFill(['status' => $sheet->published_version_id === null ? 'draft' : 'active', 'archived_at' => null])->save();
            AuditTrail::record('sheet.restored', $sheet, $by);
        });
    }

    private static function editable(LogSheet $sheet): void
    {
        if ($sheet->status === 'archived' || ! $sheet->logbook->isActive()) {
            throw ValidationException::withMessages(['fields' => 'Restore the sheet (and its logbook) before changing its template.']);
        }
    }

    /**
     * A field name keeps its kind of value across versions: reports and
     * exports read old and new entries by name, so "fuel" cannot turn from
     * a number into text. Rename the field instead.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    private static function keepsNamesTyped(LogSheet $sheet, array $fields): void
    {
        $before = [];
        foreach ($sheet->versions()->where('status', 'published')->get() as $version) {
            foreach ($version->fields as $field) {
                $before[$field['name']] ??= $field['type'];
            }
        }

        $errors = [];
        foreach ($fields as $i => $field) {
            $was = $before[$field['name']] ?? null;
            if ($was !== null && self::kind($was) !== self::kind($field['type'])) {
                $errors["fields.{$i}.name"] = "\"{$field['name']}\" held ".Fields::TYPES[$was].' answers in an earlier version. Give this field a new name.';
            }
        }
        self::fail($errors);
    }

    private static function kind(string $type): string
    {
        return match ($type) {
            'integer', 'decimal' => 'number',
            'text', 'textarea' => 'text',
            default => $type,
        };
    }

    /**
     * @param  array<string, string>  $errors
     */
    private static function fail(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
