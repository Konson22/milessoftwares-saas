<?php

namespace App\Logbook;

use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Logbook\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Which assets a log sheet records (ADR-0031 §6), many to many: one
 * Temperature log for every cold room, one generator in several sheets.
 *
 * A sheet with no links works as before: its main resource field offers
 * every active asset of its type. Once linked, the main field offers only
 * the linked assets, on the web, on the phone and when the server checks
 * the entry. A sheet that names no asset is never linked.
 */
final class SheetAssets
{
    /**
     * Ids of the assets linked to a sheet ([] when none: any asset).
     *
     * @return list<string>
     */
    public static function linkedIds(string $sheetId): array
    {
        return array_values(array_map('strval', DB::table('log_sheet_resources')->where('organization_id', app(CurrentTenant::class)->require()->id)
            ->where('log_sheet_id', $sheetId)->pluck('log_resource_id')->all()));
    }

    /**
     * Linked asset ids by sheet, for many sheets at once (the phone kit).
     *
     * @param  list<string>  $sheetIds
     * @return array<string, list<string>>
     */
    public static function linkedBySheet(array $sheetIds): array
    {
        $out = [];
        foreach (DB::table('log_sheet_resources')->where('organization_id', app(CurrentTenant::class)->require()->id)
            ->whereIn('log_sheet_id', $sheetIds)->get(['log_sheet_id', 'log_resource_id']) as $row) {
            $out[(string) $row->log_sheet_id][] = (string) $row->log_resource_id;
        }

        return $out;
    }

    /**
     * The field that names the asset on the sheet's latest template
     * (draft first: a manager linking assets is usually building it).
     *
     * @return array<string, mixed>|null
     */
    public static function subjectFieldOf(LogSheet $sheet): ?array
    {
        $version = $sheet->draftVersion ?? $sheet->publishedVersion;
        $name = Recording::subjectField($version);
        if ($name === null || ! $version instanceof LogSheetVersion) {
            return null;
        }

        return collect($version->fields)->firstWhere('name', $name);
    }

    /** Why the asset cannot be linked to the sheet, or null. */
    public static function refusal(LogSheet $sheet, LogResource $resource): ?string
    {
        if ($sheet->status === 'archived') {
            return 'This log sheet is archived.';
        }
        if ($resource->status === 'retired') {
            return 'A retired asset is not linked to new log sheets.';
        }
        $field = self::subjectFieldOf($sheet);
        if ($field === null) {
            return 'This log sheet names no asset. Add a Resource field to its template and choose it as the main asset.';
        }
        $type = $field['resource_type'] ?? null;
        if (is_string($type) && ! ResourceCategories::matches($resource, $type)) {
            return "This log sheet records {$type} assets; {$resource->code} is a {$resource->type}.";
        }

        return null;
    }

    public static function link(LogSheet $sheet, LogResource $resource, User $by): void
    {
        if (($why = self::refusal($sheet, $resource)) !== null) {
            throw ValidationException::withMessages(['link' => $why]);
        }
        DB::transaction(function () use ($sheet, $resource, $by) {
            if ($sheet->resources()->whereKey($resource->id)->exists()) {
                return;
            }
            $sheet->resources()->attach($resource->id, ['id' => (string) Str::uuid7(), 'organization_id' => $sheet->organization_id, 'created_by' => $by->id]);
            AuditTrail::record('resource.sheet_linked', $resource, $by, ['sheet' => $sheet->code, 'sheet_id' => $sheet->id]);
            AuditTrail::record('sheet.resource_linked', $sheet, $by, ['resource' => $resource->code, 'resource_id' => $resource->id]);
        });
    }

    /**
     * Unlinks. Entries already written keep naming the asset; its own
     * schedules on this sheet must be ended first, so expected readings
     * never point at an asset the sheet no longer records.
     */
    public static function unlink(LogSheet $sheet, LogResource $resource, User $by): void
    {
        $live = Schedule::query()->where('log_sheet_id', $sheet->id)->where('resource_id', $resource->id)->whereNull('ended_at')->exists();
        if ($live) {
            throw ValidationException::withMessages(['link' => "End {$resource->code}'s schedule on {$sheet->code} first."]);
        }
        DB::transaction(function () use ($sheet, $resource, $by) {
            if ($sheet->resources()->detach($resource->id) > 0) {
                AuditTrail::record('resource.sheet_unlinked', $resource, $by, ['sheet' => $sheet->code, 'sheet_id' => $sheet->id]);
                AuditTrail::record('sheet.resource_unlinked', $sheet, $by, ['resource' => $resource->code, 'resource_id' => $resource->id]);
            }
        });
    }

    /**
     * Whether the asset may be named in a field of the sheet: active, of
     * the field's type, and — in the main field of a linked sheet — one
     * of the linked assets.
     *
     * @param  list<string>|null  $linked  the sheet's links, when already read
     */
    public static function allows(string $resourceId, ?string $type, bool $isSubject, array $linked): bool
    {
        if ($isSubject && $linked !== [] && ! in_array($resourceId, $linked, true)) {
            return false;
        }

        return ResourceCategories::matching(LogResource::query()->whereKey($resourceId)->where('active', true), $type)->exists();
    }
}
