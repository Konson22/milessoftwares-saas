<?php

namespace App\Logbook;

use App\Logbook\Models\AccessGrant;
use App\Logbook\Models\Entry;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogSheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What a member reaches (ADR-0025 §2.5). Everything is already limited to
 * their organization (BelongsToTenant). Within it, three layers, none
 * granting more than the others allow:
 *
 * - the catalog role says what a member may do;
 * - the role's scope in Central says in which departments
 *   (Member::reach): a whole-workspace role reaches every logbook, a
 *   department role only logbooks of that department. A logbook without a
 *   department belongs to the whole workspace, so only whole-workspace
 *   roles reach it;
 * - the logbook's members say who, within that: a person reaches a
 *   logbook only once a manager adds them to it (the whole logbook or one
 *   sheet) as contributor, reviewer or viewer, matching their role.
 *
 * logbook.manage reaches every logbook in its departments without being a
 * member; a whole-workspace manager reaches all of them.
 *
 * Reading entries: reviewers, viewers and managers read the submitted
 * entries of the sheets they reach. Contributors read their own entries
 * only, drafts included. Anything outside answers 404, never 403, so ids
 * reveal nothing.
 */
final class Access
{
    public const CAPABILITIES = [
        'contribute' => ['logbook.entries.create', 'contributor'],
        'review' => ['logbook.entries.review', 'reviewer'],
        'view' => ['logbook.view', 'viewer'],
    ];

    public const MANAGE = 'logbook.manage';

    /** Whether the member manages logbooks somewhere (the whole workspace or their departments). */
    public static function manages(?Member $member = null): bool
    {
        return ($member ?? Member::current())->can(self::MANAGE);
    }

    /** Whether the member manages every logbook, and what belongs to no department (resources, others' routes). */
    public static function managesEverything(?Member $member = null): bool
    {
        return ($member ?? Member::current())->everywhere(self::MANAGE);
    }

    /**
     * Logbooks the member manages: all of them, or those of their departments.
     *
     * @return Builder<Logbook>
     */
    public static function managedLogbooks(?Member $member = null): Builder
    {
        $member ??= Member::current();

        return self::inDepartments(Logbook::query(), $member->reach(self::MANAGE), 'department_id');
    }

    /** A logbook the member manages, else 404. */
    public static function managedLogbook(string $id, ?Member $member = null): Logbook
    {
        return self::managedLogbooks($member)->findOrFail($id);
    }

    public static function managesLogbook(Logbook $logbook, ?Member $member = null): bool
    {
        return self::managedLogbooks($member)->whereKey($logbook->id)->exists();
    }

    /** A sheet of a logbook the member manages, else 404. */
    public static function managedSheet(string $id, ?Member $member = null): LogSheet
    {
        return LogSheet::query()->whereIn('logbook_id', self::managedLogbooks($member)->select('id'))->findOrFail($id);
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
    public static function limit(Builder $query, array $capabilities, string $logbookColumn = 'logbook_id', ?string $sheetColumn = 'log_sheet_id', ?Member $member = null): Builder
    {
        $member ??= Member::current();
        if (self::managesEverything($member)) {
            return $query;
        }

        $held = array_values(array_filter($capabilities, fn (string $c) => $member->can(self::CAPABILITIES[$c][0])));
        $managed = $member->reach(self::MANAGE);
        if ($held === [] && $managed === []) {
            return $query->whereRaw('1 = 0');
        }
        $userId = $member->id;

        return $query->where(function (Builder $q) use ($held, $managed, $member, $userId, $logbookColumn, $sheetColumn) {
            // A department manager reaches every logbook of their departments.
            if ($managed !== null && $managed !== []) {
                $q->orWhereIn($logbookColumn, Logbook::query()->select('id')->whereIn('department_id', $managed));
            }

            foreach ($held as $capability) {
                [$permission, $role] = self::CAPABILITIES[$capability];
                $departments = $member->reach($permission);

                $q->orWhere(function (Builder $one) use ($departments, $role, $userId, $logbookColumn, $sheetColumn) {
                    if ($departments !== null) {
                        $one->whereIn($logbookColumn, Logbook::query()->select('id')->whereIn('department_id', $departments));
                    }

                    // A member of the logbook, or of one of its sheets, in this role.
                    $one->where(function (Builder $listed) use ($role, $userId, $logbookColumn, $sheetColumn) {
                        $listed->whereIn($logbookColumn, AccessGrant::query()->select('logbook_id')
                            ->where('user_id', $userId)->whereNull('log_sheet_id')->where('role', $role));

                        $sheetGrants = AccessGrant::query()->select('log_sheet_id')
                            ->where('user_id', $userId)->whereNotNull('log_sheet_id')->where('role', $role);

                        if ($sheetColumn !== null) {
                            $listed->orWhereIn($sheetColumn, $sheetGrants);
                        } else {
                            // A logbook is listed when one of its sheets is granted.
                            $listed->orWhereIn($logbookColumn, LogSheet::query()->select('logbook_id')->whereIn('id', $sheetGrants));
                        }
                    });
                });
            }
        });
    }

    /**
     * Rows whose department column is in the list; null keeps every row,
     * an empty list none.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @param  list<string>|null  $departments
     * @return Builder<T>
     */
    private static function inDepartments(Builder $query, ?array $departments, string $column): Builder
    {
        return match (true) {
            $departments === null => $query,
            $departments === [] => $query->whereRaw('1 = 0'),
            default => $query->whereIn($column, $departments),
        };
    }

    public static function reachesSheet(LogSheet $sheet, string $capability, ?Member $member = null): bool
    {
        return self::limit(LogSheet::query()->whereKey($sheet->id), [$capability], 'logbook_id', 'id', $member)->exists();
    }

    /**
     * Whether the member reaches the logbook for any of the capabilities
     * (all of them by default).
     *
     * @param  list<string>|null  $capabilities
     */
    public static function reachesLogbook(string $logbookId, ?Member $member = null, ?array $capabilities = null): bool
    {
        return self::limit(Logbook::query()->whereKey($logbookId), $capabilities ?? array_keys(self::CAPABILITIES), 'id', null, $member)->exists();
    }

    /**
     * Logbooks the member sees in lists.
     *
     * @return Builder<Logbook>
     */
    public static function logbooks(?Member $member = null): Builder
    {
        return self::limit(Logbook::query(), array_keys(self::CAPABILITIES), 'id', null, $member);
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
    public static function sheets(?Member $member = null): Builder
    {
        return self::limit(LogSheet::query(), array_keys(self::CAPABILITIES), 'logbook_id', 'id', $member);
    }

    public static function sheet(string $id): LogSheet
    {
        return self::sheets()->findOrFail($id);
    }

    /**
     * Entries the member may read: their own (drafts included), and the
     * submitted entries of sheets they review, view or manage. Contributing
     * alone never shows colleagues' entries.
     *
     * @return Builder<Entry>
     */
    public static function entries(?Member $member = null): Builder
    {
        $member ??= Member::current();

        return Entry::query()->where(function (Builder $q) use ($member) {
            $q->where('created_by', $member->id)
                ->orWhere(fn (Builder $readable) => self::limit($readable->where('status', '!=', 'draft'), ['review', 'view'], member: $member));
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
    public static function reviewable(?Member $member = null): Builder
    {
        $member ??= Member::current();

        return self::limit(Entry::query(), ['review'], member: $member)
            ->where('status', 'submitted')
            ->where('created_by', '!=', $member->id);
    }

    /**
     * Sheets the member may write entries on now.
     *
     * @return Builder<LogSheet>
     */
    public static function writableSheets(?Member $member = null): Builder
    {
        return self::limit(LogSheet::query(), ['contribute'], 'logbook_id', 'id', $member)
            ->where('status', 'active')->whereNotNull('published_version_id')
            ->whereIn('logbook_id', fn (QueryBuilder $q) => $q->select('id')->from('logbooks')->where('status', 'active'));
    }
}
