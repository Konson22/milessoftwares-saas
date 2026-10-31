<?php

namespace App\Modules\Licences\Support;

use RuntimeException;

/**
 * Signs a licence into an activation code the desktop app checks offline
 * with the public half of the key: MP1.<licence>.<signature>, both
 * base64url, the licence as JSON (pharmacy-electron-app
 * src/main/licence/codes.ts reads it).
 */
final class LicenceSigner
{
    /** SPKI DER prefix of an Ed25519 public key: the app's PUBLIC_KEY is this plus the 32 key bytes. */
    private const SPKI_PREFIX = '302a300506032b6570032100';

    public function __construct(private readonly ?string $seed) {}

    public static function fromConfig(): self
    {
        $seed = config('platform.licences.signing_key');

        return new self(is_string($seed) && $seed !== '' ? $seed : null);
    }

    public function configured(): bool
    {
        return $this->seed !== null;
    }

    /**
     * @param  array{id: string, type: string, device: string, branch: string, name: string, issued: string, expires: string|null, max_devices: int, max_branches: int}  $licence
     */
    public function sign(array $licence): string
    {
        $body = json_encode($licence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($body, sodium_crypto_sign_secretkey($this->keypair()));

        return 'MP1.'.self::base64url($body).'.'.self::base64url($signature);
    }

    /** The public half, as the app's PUBLIC_KEY (SPKI, DER, base64). */
    public function publicKey(): string
    {
        return base64_encode(hex2bin(self::SPKI_PREFIX).sodium_crypto_sign_publickey($this->keypair()));
    }

    /** A new seed for PLATFORM_LICENCE_SIGNING_KEY. */
    public static function newSeed(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
    }

    private function keypair(): string
    {
        $seed = $this->seed === null ? false : base64_decode($this->seed, true);
        if ($seed === false || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new RuntimeException('PLATFORM_LICENCE_SIGNING_KEY is not set, or is not a 32-byte base64 seed.');
        }

        return sodium_crypto_sign_seed_keypair($seed);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
