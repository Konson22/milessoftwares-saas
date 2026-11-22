<?php

namespace App\Logbook;

use App\Logbook\Models\Sequence;
use App\Models\Workspace;

/**
 * References per organization, code and year: GEN-2026-000001 for entries
 * (the sheet's code), TASK-2026-000001 for tasks and EXC-2026-000001 for
 * exceptions (ADR-0026). Drawn inside the creating transaction with the
 * sequence row locked, so two people at once never get the same number
 * (each table is also unique on it). Entries draw theirs at first
 * submission, so discarded drafts leave no gaps.
 *
 * The year is the workspace's: an entry submitted just after midnight on
 * 1 January in its time zone belongs to the new year.
 */
final class References
{
    /** Sequence keys of the work records: lowercase, so no sheet code (A-Z, 0-9) can take them. */
    public const TASKS = ['sequence' => 'task', 'prefix' => 'TASK'];

    public const EXCEPTIONS = ['sequence' => 'exception', 'prefix' => 'EXC'];

    /** Sheet codes that would read like a task or exception reference. */
    public const RESERVED_CODES = ['TASK', 'EXC'];

    /**
     * @param  string  $code  the sheet code (also the prefix), or a sequence key with $prefix
     */
    public static function next(string $code, ?string $prefix = null): string
    {
        $year = (int) now(Workspace::current()->timezone ?? 'UTC')->format('Y');

        $sequence = Sequence::query()->where('code', $code)->where('year', $year)->lockForUpdate()->first()
            ?? Sequence::query()->createOrFirst(['code' => $code, 'year' => $year], ['last' => 0]);
        $sequence = Sequence::query()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();

        $next = $sequence->last + 1;
        $sequence->forceFill(['last' => $next])->save();

        return sprintf('%s-%d-%06d', $prefix ?? $code, $year, $next);
    }
}
