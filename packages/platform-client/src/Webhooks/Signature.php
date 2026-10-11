<?php

namespace Miles\PlatformClient\Webhooks;

/**
 * Checks the platform's signature on webhooks and provisioning calls:
 *
 *   Miles-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
 *
 * over the raw body, in constant time, rejecting timestamps more than five
 * minutes off (replays of old captures).
 */
final class Signature
{
    public const HEADER = 'Miles-Signature';

    public const TOLERANCE_SECONDS = 300;

    public static function valid(?string $header, string $body, string $secret, int $now): bool
    {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($name === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The header value for a body; for tests and local tooling.
     */
    public static function header(string $body, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
