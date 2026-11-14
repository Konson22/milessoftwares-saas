<?php

namespace App\Mel;

use App\Models\EvidenceFile;
use App\Models\Result;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Supporting documents on the private disk ("local", storage/app/private),
 * never under public/. They are read only through
 * App\Http\Controllers\EvidenceController, always as a download.
 *
 * A submission's evidence is fixed once it is submitted: added and removed
 * only while it is a draft or sent back.
 */
class Evidence
{
    /** Documents, spreadsheets, images and archives; nothing a browser runs. */
    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'jpg', 'jpeg', 'png', 'webp', 'zip'];

    public const MAX_KILOBYTES = 20480;

    public function __construct(private readonly AuditTrail $audit) {}

    public function attachToSubmission(Submission $submission, UploadedFile $file, bool $share, User $user): EvidenceFile
    {
        if (! $submission->isEditable()) {
            throw ValidationException::withMessages(['file' => 'Evidence is added while the result is a draft or sent back.']);
        }

        return DB::transaction(function () use ($submission, $file, $share, $user) {
            $evidence = $this->store($file, ['programme_id' => $submission->programme_id, 'submission_id' => $submission->id, 'share_with_donors' => $share], $user);
            $this->audit->record('evidence.added', $submission, $user, ['file' => $evidence->original_name]);

            return $evidence;
        });
    }

    public function attachToResult(Result $result, UploadedFile $file, bool $share, User $user): EvidenceFile
    {
        return DB::transaction(function () use ($result, $file, $share, $user) {
            $evidence = $this->store($file, ['programme_id' => $result->programme_id, 'result_id' => $result->id, 'share_with_donors' => $share], $user);
            $this->audit->record('evidence.added', $result, $user, ['file' => $evidence->original_name]);

            return $evidence;
        });
    }

    public function remove(EvidenceFile $evidence, User $user): void
    {
        $submission = $evidence->submission;

        if ($submission !== null && ! $submission->isEditable()) {
            throw ValidationException::withMessages(['file' => 'Evidence of a submitted result is kept.']);
        }

        DB::transaction(function () use ($evidence, $submission, $user) {
            $subject = $submission ?? Result::query()->findOrFail($evidence->result_id);
            $this->audit->record('evidence.removed', $subject, $user, ['file' => $evidence->original_name]);
            $evidence->delete();
        });

        Storage::disk('local')->delete($evidence->path);
    }

    /**
     * Whether someone who sees results up to $sees (Access::SEE_*) may open
     * this file.
     */
    public static function visibleTo(EvidenceFile $evidence, string $sees): bool
    {
        $status = $evidence->submission?->status;

        return match ($sees) {
            Access::SEE_ALL => true,
            Access::SEE_VERIFIED => $status === null || in_array($status, Submission::STANDING, true),
            Access::SEE_PUBLISHED => $evidence->share_with_donors && ($status === null || $status === Submission::PUBLISHED),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(UploadedFile $file, array $attributes, User $user): EvidenceFile
    {
        $extension = Str::lower($file->getClientOriginalExtension());

        if (! in_array($extension, self::EXTENSIONS, true) || $file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw ValidationException::withMessages(['file' => 'Upload a document, spreadsheet, image or zip of up to 20 MB.']);
        }

        $path = $file->storeAs('evidence/'.Str::uuid7()->toString(), Str::uuid7()->toString().'.'.$extension, 'local');
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored. Try again.']);
        }

        return EvidenceFile::query()->create([
            ...$attributes,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => mb_substr($file->getMimeType() ?? 'application/octet-stream', 0, 128),
            'size' => (int) $file->getSize(),
            'sha256' => (string) hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $user->id,
        ]);
    }
}
