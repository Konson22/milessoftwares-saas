<?php

namespace App\Modules\Identity\OpenId;

use Laravel\Passport\Passport;

/**
 * The Passport RSA key that signs access and id tokens.
 */
final class SigningKey
{
    /**
     * RFC 7638 JWK thumbprint of the public key, used as the `kid` header and
     * in the JWKS so clients can select the right key across rotations.
     * Null when no key is configured yet.
     */
    public static function kid(): ?string
    {
        $publicKey = self::publicKey();

        if ($publicKey === null) {
            return null;
        }

        $details = openssl_pkey_get_details(openssl_pkey_get_public($publicKey) ?: throw new \RuntimeException('Invalid Passport public key.'));

        if (! is_array($details) || ! isset($details['rsa'])) {
            return null;
        }

        $jwk = json_encode([
            'e' => self::base64Url($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => self::base64Url($details['rsa']['n']),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::base64Url(hash('sha256', $jwk, true));
    }

    private static function publicKey(): ?string
    {
        $configured = config('passport.public_key');

        if (is_string($configured) && $configured !== '') {
            return str_replace('\\n', "\n", $configured);
        }

        $path = Passport::keyPath('oauth-public.key');

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
