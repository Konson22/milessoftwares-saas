<?php

namespace Miles\SharedModuleKit;

use RuntimeException;

/**
 * Turns the platform's JWKS (GET {auth host}/oauth/jwks) into the PEM key
 * EventTokenVerifier needs. Cache the result; refetch when a token arrives
 * with an unknown kid (key rotation).
 */
final class Jwks
{
    /**
     * @param  string  $jwksJson  the JWKS document
     * @param  string|null  $kid  pick this key; the first RSA key when null
     */
    public static function publicKeyPem(string $jwksJson, ?string $kid = null): string
    {
        $keys = json_decode($jwksJson, true)['keys'] ?? null;

        if (! is_array($keys)) {
            throw new RuntimeException('Not a JWKS document.');
        }

        foreach ($keys as $key) {
            if (! is_array($key) || ($key['kty'] ?? null) !== 'RSA' || ! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
                continue;
            }

            if ($kid === null || ($key['kid'] ?? null) === $kid) {
                return self::rsaToPem(self::decode($key['n']), self::decode($key['e']));
            }
        }

        throw new RuntimeException('No matching RSA key in the JWKS.');
    }

    /**
     * Unverified read of a token's kid header, to choose the JWKS key.
     */
    public static function kidOf(string $jwt): ?string
    {
        $header = json_decode(self::decode(explode('.', preg_replace('/^Bearer\s+/i', '', trim($jwt)) ?? '')[0]), true);

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }

    private static function rsaToPem(string $modulus, string $exponent): string
    {
        $rsaKey = self::der("\x30", self::integer($modulus).self::integer($exponent));
        $algorithm = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $spki = self::der("\x30", $algorithm.self::der("\x03", "\x00".$rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        // A leading high bit would read as negative.
        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return self::der("\x02", $bytes);
    }

    private static function der(string $tag, string $content): string
    {
        $length = strlen($content);
        $encoded = $length < 0x80 ? chr($length) : chr(0x80 | strlen(ltrim(pack('N', $length), "\x00"))).ltrim(pack('N', $length), "\x00");

        return $tag.$encoded.$content;
    }

    private static function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
