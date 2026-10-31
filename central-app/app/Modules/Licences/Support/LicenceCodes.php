<?php

namespace App\Modules\Licences\Support;

/**
 * The short codes people type or read out over the phone, in Crockford's
 * Base32 (no I, L, O or U), as the desktop app shows them
 * (pharmacy-electron-app src/main/licence/codes.ts):
 *
 * - a licence key: MILES-7KQ2-M9XD-4FTA (60 random bits)
 * - a device code: 7KQ2-M9XD-4FTA
 * - a pharmacy ID: AB12-CD34
 */
final class LicenceCodes
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function newKey(): string
    {
        $chars = '';
        foreach (str_split(random_bytes(12)) as $byte) {
            $chars .= self::ALPHABET[ord($byte) & 31];
        }

        return 'MILES-'.implode('-', str_split($chars, 4));
    }

    /** As typed: any case, with or without dashes, O for 0 and I or L for 1. */
    public static function normalize(string $code): string
    {
        $plain = preg_replace('/[^0-9A-Z]/', '', strtoupper($code)) ?? '';

        return strtr($plain, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    /** A key's twelve characters, without its MILES prefix; null when it is not one. */
    public static function keyBody(string $key): ?string
    {
        $plain = self::normalize($key);
        if (str_starts_with($plain, 'M1')) {
            // MILES read through normalize: M, I→1, L→1, E, S.
            $plain = preg_replace('/^M11ES/', '', $plain) ?? $plain;
        }

        return preg_match('/^[0-9A-HJKMNP-TV-Z]{12}$/', $plain) === 1 ? $plain : null;
    }

    public static function hashKey(string $key): ?string
    {
        $body = self::keyBody($key);

        return $body === null ? null : hash('sha256', 'miles-licence-key:'.$body);
    }

    /** Grouped in fours as the app shows it; null when it is not a code of that length. */
    public static function format(string $code, int $length): ?string
    {
        $plain = self::normalize($code);
        if (strlen($plain) !== $length || preg_match('/^[0-9A-HJKMNP-TV-Z]+$/', $plain) !== 1) {
            return null;
        }

        return implode('-', str_split($plain, 4));
    }

    public static function device(string $code): ?string
    {
        return self::format($code, 12);
    }

    public static function pharmacy(string $code): ?string
    {
        return self::format($code, 8);
    }
}
