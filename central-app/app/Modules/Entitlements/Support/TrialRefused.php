<?php

namespace App\Modules\Entitlements\Support;

use RuntimeException;

/**
 * Why a free trial was not started. RUNNING is the answer to a repeated
 * request for a trial that is already on, so callers can treat it as done.
 */
final class TrialRefused extends RuntimeException
{
    public const RUNNING = 'running';

    public const USED = 'used';

    public const ACTIVE = 'active';

    public function __construct(public readonly string $reason, string $application)
    {
        parent::__construct(match ($reason) {
            self::RUNNING => "The {$application} trial is already running.",
            self::ACTIVE => "This organization already has {$application}.",
            default => "This organization has already tried {$application}.",
        });
    }
}
