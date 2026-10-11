<?php

namespace App\Modules\Integration\Support;

/**
 * Signature scheme for outgoing webhooks, documented for product teams in
 * docs/integration.md:
 *
 *   Miles-Signature: t=<unix timestamp>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
 *
 * Receivers recompute the HMAC, compare in constant time, and reject
 * timestamps more than five minutes old to stop replays.
 */
final class WebhookSignature
{
    public const HEADER = 'Miles-Signature';

    public const TOLERANCE_SECONDS = 300;

    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Reference verification, used by the sample app and the tests.
     */
    public static function verify(string $secret, string $body, string $header, ?int $now = null): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];

        if (abs(($now ?? time()) - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $parts['v1']);
    }
}
