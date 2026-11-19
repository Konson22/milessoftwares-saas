<?php

namespace App\Logbook;

use App\Logbook\Models\Attachment;
use App\Logbook\Models\Entry;
use App\Logbook\Models\EntryRevision;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Evidence on entries: photos and documents answering a file field. Kept
 * on the private disk under the organization and entry, with a generated
 * file name (the original name is only a label); served only through
 * EntryController to someone who can read the entry. A file that was part
 * of a submitted revision is never deleted, only taken off the entry.
 */
final class Attachments
{
    public const DISK = 'local';

    public const MAX_KILOBYTES = 10240;

    public const MIMES = 'jpg,jpeg,png,webp,heic,pdf,doc,docx,xls,xlsx,csv,txt';

    /**
     * Adds a file to a field of a draft or returned entry. With $dedupe
     * (the phone app, which resends after a lost reply), the same file sent
     * again to the same field (by its SHA-256) returns the attachment the
     * first upload made instead of a copy.
     */
    public static function add(Entry $entry, string $field, UploadedFile $file, User $by, bool $dedupe = false): Attachment
    {
        $definition = Fields::byName($entry->version->fields)[$field] ?? null;
        if ($definition === null || $definition['type'] !== 'file') {
            throw ValidationException::withMessages(['field' => 'This sheet has no such file field.']);
        }

        $sha256 = (string) hash_file('sha256', (string) $file->getRealPath());

        $extension = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
        $path = $file->storeAs("logbook/{$entry->organization_id}/{$entry->id}", Str::uuid7()->toString().'.'.$extension, self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored. Try again.']);
        }

        try {
            $attachment = DB::transaction(function () use ($entry, $field, $file, $by, $path, $sha256, $dedupe) {
                $entry = Entries::lockForAuthor($entry, $by);
                $same = $dedupe ? $entry->attachments()->whereNull('removed_at')->where('field', $field)->where('sha256', $sha256)->first() : null;
                if ($same instanceof Attachment) {
                    return $same;
                }
                $count = $entry->attachments()->whereNull('removed_at')->where('field', $field)->count();
                if ($count >= Fields::MAX_FILES) {
                    throw ValidationException::withMessages(['file' => 'At most '.Fields::MAX_FILES.' files per field.']);
                }

                $attachment = $entry->attachments()->create([
                    'field' => $field,
                    'name' => self::safeName($file->getClientOriginalName()),
                    'path' => $path,
                    'size' => (int) $file->getSize(),
                    'mime' => $file->getMimeType(),
                    'sha256' => $sha256,
                    'uploaded_by' => $by->id,
                ]);
                self::syncValue($entry);

                return $attachment;
            });
        } catch (\Throwable $e) {
            // Nothing recorded: no orphan file either.
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }

        // A repeat: the copy just stored is not needed.
        if ($attachment->path !== $path) {
            Storage::disk(self::DISK)->delete($path);
        }

        return $attachment;
    }

    public static function remove(Attachment $attachment, User $by): void
    {
        $path = DB::transaction(function () use ($attachment, $by) {
            $entry = Entries::lockForAuthor($attachment->entry, $by);
            $attachment = Attachment::query()->whereKey($attachment->id)->lockForUpdate()->firstOrFail();
            if ($attachment->removed_at !== null) {
                return null;
            }

            $submitted = EntryRevision::query()->where('log_entry_id', $entry->id)->get(['values'])
                ->contains(fn (EntryRevision $r) => in_array($attachment->id, (array) ($r->values[$attachment->field] ?? []), true));

            if ($submitted) {
                $attachment->forceFill(['removed_at' => now()])->save();
                $path = null;
            } else {
                $path = $attachment->path;
                $attachment->delete();
            }
            self::syncValue($entry);

            return $path;
        });

        if ($path !== null) {
            self::deleteFiles([$path]);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    public static function deleteFiles(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk(self::DISK)->delete($paths);
        }
    }

    /** The entry's file values mirror its live attachments. */
    private static function syncValue(Entry $entry): void
    {
        $values = $entry->values;
        foreach ($entry->version->fields as $field) {
            if ($field['type'] === 'file') {
                unset($values[$field['name']]);
            }
        }
        foreach (Entries::files($entry) as $field => $ids) {
            $values[$field] = $ids;
        }
        $entry->forceFill(['values' => $values])->save();
    }

    private static function safeName(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $name);
        $name = trim($name, " .\t");

        return mb_substr($name !== '' ? $name : 'file', 0, 200);
    }
}
