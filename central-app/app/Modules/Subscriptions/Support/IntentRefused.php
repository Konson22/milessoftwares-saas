<?php

namespace App\Modules\Subscriptions\Support;

use RuntimeException;

/**
 * Why a registration intent could not be completed. The message is meant
 * for the person: it says what to do next, never what failed inside.
 */
final class IntentRefused extends RuntimeException
{
    public const EXPIRED = 'expired';

    public const UNAVAILABLE = 'unavailable';

    public const TRIAL_USED = 'trial_used';

    public const NOT_OWNER = 'not_owner';

    public const OTHER_ACCOUNT = 'other_account';

    public const USED_ELSEWHERE = 'used_elsewhere';

    public const ORGANIZATION_CLOSED = 'organization_closed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
