<?php

namespace App\Logbook;

use App\Logbook\Models\Sequence;

/**
 * Entry references per organization, sheet code and year: GEN-2026-000001.
 * Drawn inside the submitting transaction with the sequence row locked, so
 * two people submitting at once never get the same number (log_entries is
 * also unique on it). Drawn at first submission, so discarded drafts leave
 * no gaps.
 */
final class References
{
    public static function next(string $code): string
    {
        $year = (int) now()->format('Y');

        $sequence = Sequence::query()->where('code', $code)->where('year', $year)->lockForUpdate()->first()
            ?? Sequence::query()->createOrFirst(['code' => $code, 'year' => $year], ['last' => 0]);
        $sequence = Sequence::query()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();

        $next = $sequence->last + 1;
        $sequence->forceFill(['last' => $next])->save();

        return sprintf('%s-%d-%06d', $code, $year, $next);
    }
}
