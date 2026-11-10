<?php

namespace App\Enums;

/**
 * A report owed to a funder: due, then submitted; the funder accepts it or
 * asks for a revision (which makes it owed again).
 */
enum GrantReportStatus: string
{
    case Due = 'due';
    case Submitted = 'submitted';
    case RevisionRequested = 'revision_requested';
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::Due => 'Due',
            self::Submitted => 'Submitted',
            self::RevisionRequested => 'Revision requested',
            self::Accepted => 'Accepted',
        };
    }

    /** Still owed to the funder: blocks closing the grant. */
    public function isOutstanding(): bool
    {
        return $this === self::Due || $this === self::RevisionRequested;
    }

    /**
     * Where it can go from here.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Due, self::RevisionRequested => [self::Submitted],
            self::Submitted => [self::Accepted, self::RevisionRequested],
            self::Accepted => [],
        };
    }
}
