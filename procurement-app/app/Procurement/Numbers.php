<?php

namespace App\Procurement;

use App\Procurement\Models\DocumentSequence;

/**
 * Document numbers per organization and year: REQ-2026-0001. Drawn inside
 * the caller's transaction with the sequence row locked, so two people
 * saving at once never get the same number (each table is also unique on
 * it).
 */
final class Numbers
{
    public const PREFIXES = [
        'plan' => 'PP',
        'requisition' => 'REQ',
        'sourcing' => 'RFQ',
        'order' => 'PO',
        'receipt' => 'GRN',
    ];

    public static function next(string $type): string
    {
        $year = (int) now()->format('Y');
        $prefix = self::PREFIXES[$type];

        $sequence = DocumentSequence::query()->where('type', $type)->where('year', $year)->lockForUpdate()->first()
            ?? DocumentSequence::query()->createOrFirst(['type' => $type, 'year' => $year], ['last' => 0]);
        $sequence = DocumentSequence::query()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();

        $next = (int) $sequence->getAttribute('last') + 1;
        $sequence->forceFill(['last' => $next])->save();

        return sprintf('%s-%d-%04d', $prefix, $year, $next);
    }
}
