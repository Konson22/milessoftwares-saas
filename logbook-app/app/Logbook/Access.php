<?php

namespace App\Logbook;

use App\Logbook\Models\AccessGrant;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogSheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * What a member reaches (ADR-0025 §2.5). Everything is already limited to
 * their organization (BelongsToTenant). Within it, the catalog says what a
 * member may do and logbook access lists say where:
 *
 * - logbook.manage (and workspace owners): every logbook and sheet;
 * - an open logbook: every member holding the capability's permission;
 * - a restricted logbook: members listed on it (whole logbook or one sheet)
 *   with the matching role, who also hold the permission.
 *
 * Reading entries: whoever can view, review or contribute on a sheet reads
 * its submitted entries. Drafts are seen by their author only. Anything
 * outside answers 404, never 403, so ids reveal nothing.
 */
final class Access
{
    public const CAPABILITIES = [
        'contribute' => ['logbook.entries.create', 'contributor'],
        'review' => ['logbook.entries.review', 'reviewer'],
        'view' => ['logbook.view', 'viewer'],
    ];

    public static function manages(): bool
    {
        return Gate::allows('logbook.manage');
    }

    /**
     * Limits a query on a table with logbook_id (and log_sheet_id unless
     * $sheetColumn is null) to what the member reaches for the capabilities.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @param  list<string>  $capabilities
     * @return Builder<T>
     */
    public static function limit(Builder $query, array $capabilities, string $logbookColumn = 'logbook_id', ?string $sheetColumn = 'log_sheet_id'): Builder
    {
        if (self::manages()) {
            return $query;
        }

        $held = array_values(array_filter($capabilities, fn (string $c) => Gate::allows(self::CAPABILITIES[$c][0])));
        if ($held === []) {
            return $query->whereRaw('1 = 0');
        }
        $roles = array_map(fn (string $c) => self::CAPABILITIES[$c][1], $held);
        $userId = Auth::id();

        return $query->where(function (Builder $q) use ($roles, $userId, $logbookColumn, $sheetColumn) {
            $q->whereIn($logbookColumn, Logbook::query()->select('id')->where('restricted', false))
                ->orWhereIn($logbookColumn, AccessGrant::query()->select('logbook_id')
                    ->where('user_id', $userId)->whereNull('log_sheet_id')->whereIn('role', $roles));

            $sheetGrants = AccessGrant::query()->select('log_sheet_id')
                ->where('user_id', $userId)->whereNotNull('log_sheet_id')->whereIn('role', $roles);

            if ($sheetColumn !== null) {
                $q->orWhereIn($sheetColumn, $sheetGrants);
            } else {
                // A logbook is listed when one of its sheets is granted.
                $q->orWhereIn($logbookColumn, LogSheet::query()->select('logbook_id')->whereIn('id', $sheetGrants));
            }
        });
    }

    public static function reachesSheet(LogSheet $sheet, string $capability): bool
    {
        return self::limit(LogSheet::query()->whereKey($sheet->id), [$capability], 'logbook_id', 'id')->exists();
    }

    /**
     * Logbooks the member sees in lists.
     *
     * @return Builder<Logbook>
     */
    public static function logbooks(): Builder
    {
        return self::limit(Logbook::query(), array_keys(self::CAPABILITIES), 'id', null);
    }

    public static function logbook(string $id): Logbook
    {
        return self::logbooks()->findOrFail($id);
    }

    /**
     * Sheets the member reaches for any capability.
     *
     * @return Builder<LogSheet>
     */
    public static function sheets(): Builder
    {
        return self::limit(LogSheet::query(), array_keys(self::CAPABILITIES), 'logbook_id', 'id');
    }

    public static function sheet(string $id): LogSheet
    {
        return self::sheets()->findOrFail($id);
    }

    /**
     * Entries the member may read: submitted entries on sheets they reach,
     * and their own drafts.
     *
     * @return Builder<Entry>
     */
    public static function entries(): Builder
    {
        $userId = Auth::id();

        return Entry::query()->where(function (Builder $q) use ($userId) {
            $q->where('created_by', $userId)
                ->orWhere(fn (Builder $readable) => self::limit($readable->where('status', '!=', 'draft'), array_keys(self::CAPABILITIES)));
        });
    }

    public static function entry(string $id): Entry
    {
        return self::entries()->findOrFail($id);
    }

    /**
     * Submitted entries waiting on this member's review: never their own.
     *
     * @return Builder<Entry>
     */
    public static function reviewable(): Builder
    {
        return self::limit(Entry::query(), ['review'])
            ->where('status', 'submitted')
            ->where('created_by', '!=', Auth::id());
    }

    /**
     * Sheets the member may write entries on now.
     *
     * @return Builder<LogSheet>
     */
    public static function writableSheets(): Builder
    {
        return self::limit(LogSheet::query(), ['contribute'], 'logbook_id', 'id')
            ->where('status', 'active')->whereNotNull('published_version_id')
            ->whereIn('logbook_id', fn (QueryBuilder $q) => $q->select('id')->from('logbooks')->where('status', 'active'));
    }
}
