<?php

namespace App\Collaboration;

use App\Collaboration\Models\Attachment;
use App\Collaboration\Models\ProjectRequest;
use App\Collaboration\Models\Report;
use App\Collaboration\Models\ReportVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Files on a report's open version or on a request still being written,
 * added by the party writing it, opened by those who may see it. A
 * submitted version's files stay with it.
 */
final class Attachments
{
    public const DISK = 'local';

    /** The same kinds of file as documents. */
    public const RULES = ['required', 'file', 'max:'.Attachment::MAX_KILOBYTES, 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,csv,txt,png,jpg,jpeg,webp'];

    /**
     * @throws ValidationException
     */
    public static function toReport(Report $report, Acting $acting, UploadedFile $file): Attachment
    {
        abort_unless(Reports::isSubmitter($report, $acting), 403);
        $acting->require('projects.reports.prepare', Capability::REPORTS_SUBMIT);
        $version = $report->currentVersion;
        if (! in_array($report->status, Report::EDITABLE, true) || $version === null || $version->isFrozen()) {
            throw ValidationException::withMessages(['file' => 'Save the report as a draft first; submitted versions keep their files.']);
        }

        return self::store($report->organization_id, Attachment::REPORT_VERSION, $version->id, $acting, $file);
    }

    /**
     * @throws ValidationException
     */
    public static function toRequest(ProjectRequest $request, Acting $acting, UploadedFile $file): Attachment
    {
        abort_unless(Requests::isRaiser($request, $acting), 403);
        $acting->require('projects.requests.raise', Capability::REQUESTS_CREATE);
        if (! in_array($request->status, ProjectRequest::EDITABLE, true)) {
            throw ValidationException::withMessages(['file' => 'Files are added while the request is being written.']);
        }

        return self::store($request->organization_id, Attachment::REQUEST, $request->id, $acting, $file);
    }

    /**
     * @return Collection<int, Attachment>
     */
    public static function of(string $type, string $id): Collection
    {
        return Attachment::query()->with('uploader')->where('attachable_type', $type)->where('attachable_id', $id)->orderBy('created_at')->get();
    }

    /**
     * Whether the attachment belongs to this report (any of its versions).
     */
    public static function belongsToReport(Attachment $attachment, Report $report): bool
    {
        return $attachment->attachable_type === Attachment::REPORT_VERSION
            && ReportVersion::query()->whereKey($attachment->attachable_id)->where('report_id', $report->id)->exists();
    }

    public static function belongsToRequest(Attachment $attachment, ProjectRequest $request): bool
    {
        return $attachment->attachable_type === Attachment::REQUEST && $attachment->attachable_id === $request->id;
    }

    private static function store(string $ownerId, string $type, string $id, Acting $acting, UploadedFile $file): Attachment
    {
        if (self::of($type, $id)->count() >= 20) {
            throw ValidationException::withMessages(['file' => 'At most 20 files.']);
        }
        $path = $file->store("organizations/{$ownerId}/collaboration/{$type}/{$id}", self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be saved.']);
        }

        try {
            return DB::transaction(function () use ($ownerId, $type, $id, $acting, $file, $path) {
                $attachment = new Attachment;
                $attachment->forceFill([
                    'organization_id' => $ownerId,
                    'attachable_type' => $type,
                    'attachable_id' => $id,
                    'name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'path' => $path,
                    'mime' => $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                    'uploaded_by' => $acting->user->id,
                    'acting_organization_id' => $acting->organizationId(),
                    'created_at' => now(),
                ])->save();

                return $attachment;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }
    }
}
