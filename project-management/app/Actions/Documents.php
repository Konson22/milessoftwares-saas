<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The organization's documents. A document is archived, never deleted, and its
 * versions stay as they were uploaded. Each keeps a SHA-256 of its file, so a
 * copy can be checked against the original.
 */
final class Documents
{
    public const DISK = 'local';

    /**
     * @param  array{title: string, category: string, description: string|null, confidential: bool}  $details
     *
     * @throws ValidationException
     */
    public static function create(array $details, UploadedFile $file, User $by): Document
    {
        $document = new Document($details);
        $document->forceFill(['created_by' => $by->id]);

        return self::storing($file, $document, function (string $path) use ($document, $file, $by) {
            $document->save();
            self::addVersionRow($document, $file, $path, null, $by);
            Audit::record('document.created', $document, ['title' => $document->title, 'confidential' => $document->confidential], actor: $by);

            return $document;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function addVersion(Document $document, UploadedFile $file, ?string $note, User $by): DocumentVersion
    {
        if ($document->archived_at !== null) {
            throw ValidationException::withMessages(['file' => 'Restore this document before adding a version.']);
        }

        return self::storing($file, $document, function (string $path) use ($document, $file, $note, $by) {
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $version = self::addVersionRow($locked, $file, $path, $note, $by);
            Audit::record('document.version_added', $locked, ['title' => $locked->title, 'version' => $version->version, 'sha256' => $version->sha256], actor: $by);

            return $version;
        });
    }

    /**
     * @param  array{title: string, category: string, description: string|null, confidential: bool}  $details
     */
    public static function update(Document $document, array $details, User $by): void
    {
        $fields = array_keys($details);
        $before = $document->only($fields);
        $document->fill($details)->save();

        Audit::change('document.updated', $document, $before, $document->only($fields), actor: $by);
    }

    public static function archive(Document $document, User $by): void
    {
        $document->forceFill(['archived_at' => now()])->save();
        Audit::record('document.archived', $document, ['title' => $document->title], actor: $by);
    }

    public static function restore(Document $document, User $by): void
    {
        $document->forceFill(['archived_at' => null])->save();
        Audit::record('document.restored', $document, ['title' => $document->title], actor: $by);
    }

    /**
     * Stores the file, then runs the rest in a transaction. If that fails, the
     * stored file goes too, so nothing is left that no record points to.
     *
     * @template T
     *
     * @param  callable(string): T  $then
     * @return T
     *
     * @throws ValidationException
     */
    private static function storing(UploadedFile $file, Document $document, callable $then): mixed
    {
        $document->id ??= $document->newUniqueId();
        $organization = app(CurrentOrganization::class)->require();
        $path = $file->store("organizations/{$organization->id}/documents/{$document->id}", self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored.']);
        }

        try {
            return DB::transaction(fn () => $then($path));
        } catch (Throwable $failure) {
            Storage::disk(self::DISK)->delete($path);

            throw $failure;
        }
    }

    private static function addVersionRow(Document $document, UploadedFile $file, string $path, ?string $note, User $by): DocumentVersion
    {
        $number = $document->current_version + 1;
        $version = $document->versions()->create([
            'version' => $number,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'sha256' => (string) hash_file('sha256', (string) $file->getRealPath()),
            'note' => $note,
            'uploaded_by' => $by->id,
        ]);
        $document->forceFill(['current_version' => $number])->save();

        return $version;
    }
}
