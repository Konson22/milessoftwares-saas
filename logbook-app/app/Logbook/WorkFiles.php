<?php

namespace App\Logbook;

use App\Logbook\Models\LogException;
use App\Logbook\Models\Task;
use App\Logbook\Models\WorkFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Files on tasks and exceptions: a task's brief (by its managers, while
 * open), evidence of the work (by the assignee, while it is still theirs
 * to do) and evidence of an exception's resolution. Same private disk,
 * types and size limit as entry attachments; deduplicated by SHA-256 per
 * record and kind, so the phone may resend after a lost reply. Served only
 * through controllers that check the record is visible.
 */
final class WorkFiles
{
    public const MAX_FILES = 10;

    public static function add(Task|LogException $owner, string $kind, UploadedFile $file, User $by): WorkFile
    {
        self::mayChange($owner, $kind, $by);
        $sha256 = (string) hash_file('sha256', (string) $file->getRealPath());
        $column = $owner instanceof Task ? 'task_id' : 'exception_id';

        $same = WorkFile::query()->where($column, $owner->id)->where('kind', $kind)->where('sha256', $sha256)->first();
        if ($same !== null) {
            return $same;
        }
        if (WorkFile::query()->where($column, $owner->id)->where('kind', $kind)->count() >= self::MAX_FILES) {
            throw ValidationException::withMessages(['file' => 'At most '.self::MAX_FILES.' files here.']);
        }

        $extension = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
        $folder = $owner instanceof Task ? 'tasks' : 'exceptions';
        $path = $file->storeAs("logbook/{$owner->organization_id}/{$folder}/{$owner->id}", Str::uuid7()->toString().'.'.$extension, Attachments::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored. Try again.']);
        }

        try {
            return DB::transaction(function () use ($owner, $kind, $file, $by, $path, $sha256, $column) {
                $record = WorkFile::query()->create([
                    $column => $owner->id,
                    'kind' => $kind,
                    'name' => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $file->getClientOriginalName()), " .\t") ?: 'file', 0, 200),
                    'path' => $path,
                    'size' => (int) $file->getSize(),
                    'mime' => $file->getMimeType(),
                    'sha256' => $sha256,
                    'uploaded_by' => $by->id,
                ]);
                AuditTrail::record($owner instanceof Task ? 'task.file_added' : 'exception.file_added', $owner, $by, ['file' => $record->name, 'kind' => $kind]);

                return $record;
            });
        } catch (\Throwable $e) {
            Storage::disk(Attachments::DISK)->delete($path);

            throw $e;
        }
    }

    /** A file is removed only while its record is still being worked on, by whoever may add it. */
    public static function remove(WorkFile $file, User $by): void
    {
        $owner = $file->task_id !== null ? Task::query()->findOrFail($file->task_id) : LogException::query()->findOrFail($file->exception_id);
        self::mayChange($owner, $file->kind, $by);
        DB::transaction(function () use ($file, $owner, $by) {
            AuditTrail::record($owner instanceof Task ? 'task.file_removed' : 'exception.file_removed', $owner, $by, ['file' => $file->name]);
            $file->delete();
        });
        Storage::disk(Attachments::DISK)->delete($file->path);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(WorkFile $file): array
    {
        return ['id' => $file->id, 'kind' => $file->kind, 'name' => $file->name, 'size' => $file->size, 'mime' => $file->mime, 'sha256' => $file->sha256];
    }

    private static function mayChange(Task|LogException $owner, string $kind, User $by): void
    {
        if ($owner instanceof Task) {
            $allowed = match ($kind) {
                'brief' => $owner->isOpen() && Tasks::manages($owner),
                'evidence' => $owner->isOpen() && $owner->assignee_id === $by->id,
                default => false,
            };
        } else {
            $allowed = $kind === 'evidence' && $owner->isOpen() && ($owner->assignee_id === $by->id || Exceptions::manages($owner));
        }
        if (! $allowed) {
            throw ValidationException::withMessages(['file' => 'Files are added here only while the work is open, by the person doing it (or, for a briefing, a manager).']);
        }
    }
}
