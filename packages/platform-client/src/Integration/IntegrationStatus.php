<?php

namespace Miles\PlatformClient\Integration;

/**
 * How a read of a paired product's integration API went (ADR-0019 §9.4).
 * Each state is shown for what it is: none of them means "zero results".
 */
enum IntegrationStatus: string
{
    /** Answered; the data is what the other product holds. */
    case Ok = 'ok';

    /** This deployment has no URL for the other product. */
    case NotConfigured = 'not_configured';

    /** The organization is not (or no longer) entitled to both products. */
    case NotEntitled = 'not_entitled';

    /** The other product, or the platform, refused this user or this read. */
    case Denied = 'denied';

    /** The other product does not have the record asked for. */
    case NotFound = 'not_found';

    /** The other product, or the platform, could not be reached or failed. */
    case Unavailable = 'unavailable';

    /** No answer in time. */
    case Timeout = 'timeout';

    /** The request was refused as malformed (a bug on the calling side). */
    case Invalid = 'invalid';

    public function ok(): bool
    {
        return $this === self::Ok;
    }

    /** Worth trying again later, as opposed to an answer. */
    public function transient(): bool
    {
        return in_array($this, [self::Unavailable, self::Timeout], true);
    }
}
