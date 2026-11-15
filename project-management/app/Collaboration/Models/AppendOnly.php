<?php

namespace App\Collaboration\Models;

use LogicException;

/**
 * History that is written once and never changed or removed: review steps
 * and request decisions. Refused here in the application on every
 * database, in addition to the PostgreSQL triggers (ADR-0015).
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' is append-only.'));
        static::deleting(fn () => throw new LogicException(static::class.' is append-only.'));
    }
}
