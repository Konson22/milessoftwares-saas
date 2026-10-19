<?php

namespace App\Modules\Documents\Actions;

use App\Models\User;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Models\Document;
use App\Modules\Employees\Models\Employee;
use App\Support\HrAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeping files on someone's record. The stored path is HR's own
 * ({organization}/{employee}/{id}); the uploaded name is kept only as the
 * name to download it under. Every upload, download and removal is in
 * the HR audit log.
 */
class ManageDocuments
{
    public function __construct(private readonly HrAudit $audit) {}

    /**
     * @param  array{category: DocumentCategory, title: string, issued_on: ?string, expires_on: ?string, note: ?string}  $details
     */
    public function upload(Employee $employee, UploadedFile $file, array $details, User $by): Document
    {
        $id = (string) Str::uuid7();
        $directory = "{$employee->organization_id}/{$employee->id}";
        $path = "{$directory}/{$id}";
        $disk = Storage::disk(Document::DISK);

        $disk->putFileAs($directory, $file, $id);

        try {
            return DB::transaction(function () use ($employee, $file, $details, $by, $id, $path) {
                $document = $employee->documents()->create([
                    'id' => $id,
                    'category' => $details['category'],
                    'title' => $details['title'],
                    'issued_on' => $details['issued_on'],
                    'expires_on' => $details['expires_on'],
                    'note' => $details['note'],
                    'path' => $path,
                    'original_name' => $this->safeName($file),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => (string) hash_file('sha256', (string) $file->getRealPath()),
                    'uploaded_by' => $by->id,
                ]);
                $this->audit->record($by, 'documents.uploaded', $employee, null, $document->id);

                return $document;
            });
        } catch (Throwable $exception) {
            // No file without its row.
            $disk->delete($path);

            throw $exception;
        }
    }

    /**
     * Records the download, for the controller to stream the file.
     */
    public function downloaded(Document $document, User $by): void
    {
        $this->audit->record($by, 'documents.downloaded', $document->employee, null, $document->id);
    }

    /**
     * Deletes the file; the row stays, marked removed, so the list still
     * shows that it was there and who removed it.
     */
    public function remove(Document $document, User $by): void
    {
        DB::transaction(function () use ($document, $by) {
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->isRemoved()) {
                return;
            }

            $locked->forceFill(['removed_at' => CarbonImmutable::now(), 'removed_by' => $by->id])->save();
            $this->audit->record($by, 'documents.removed', $document->employee, null, $document->id);
            $document->setRawAttributes($locked->getAttributes(), true);
        });

        // After the commit: a failed delete leaves an orphan file, never a
        // row that points at nothing.
        Storage::disk(Document::DISK)->delete($document->path);
    }

    /**
     * The uploaded name, as a download name: no directories or control
     * characters, bounded, and the extension of what the file really is.
     */
    private function safeName(UploadedFile $file): string
    {
        $base = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\"]+/u', '', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $base = trim($base, " .\t");

        return Str::limit($base === '' ? 'document' : $base, 200, '').'.'.($file->guessExtension() ?? 'bin');
    }
}
