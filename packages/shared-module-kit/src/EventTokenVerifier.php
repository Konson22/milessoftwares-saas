<?php

namespace Miles\SharedModuleKit;

use DateInterval;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Verifies event tokens minted by the platform (POST /api/v1/event-tokens):
 * RS256 signature by the platform key, issuer, audience = this module,
 * unexpired, with the organization and source application claims present.
 */
final class EventTokenVerifier
{
    /** @var non-empty-string */
    private readonly string $issuer;

    /** @var non-empty-string */
    private readonly string $module;

    /**
     * @param  string  $issuer  the platform auth host URL
     * @param  string  $publicKeyPem  the platform signing key (from its JWKS)
     * @param  string  $module  this module's application code: finance, hr or payroll
     */
    public function __construct(
        string $issuer,
        private readonly string $publicKeyPem,
        string $module,
        private readonly ?ClockInterface $clock = null,
    ) {
        if ($issuer === '' || $module === '') {
            throw new \InvalidArgumentException('The issuer and module are required.');
        }

        $this->issuer = $issuer;
        $this->module = $module;
    }

    /**
     * Accepts the raw token or an "Authorization: Bearer …" header value.
     *
     * @throws InvalidEventToken
     */
    public function verify(string $token): EventToken
    {
        $jwt = trim(preg_replace('/^Bearer\s+/i', '', $token) ?? '');

        if ($jwt === '' || $this->publicKeyPem === '') {
            throw new InvalidEventToken('missing token');
        }

        $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText('-'), InMemory::plainText($this->publicKeyPem));

        try {
            $parsed = $config->parser()->parse($jwt);
        } catch (Throwable) {
            throw new InvalidEventToken('unreadable token');
        }

        if (! $parsed instanceof Plain) {
            throw new InvalidEventToken('unexpected token type');
        }

        try {
            $config->validator()->assert(
                $parsed,
                new SignedWith($config->signer(), $config->verificationKey()),
                new IssuedBy($this->issuer),
                new PermittedFor($this->module),
                new StrictValidAt($this->clock ?? new class implements ClockInterface
                {
                    public function now(): DateTimeImmutable
                    {
                        return new DateTimeImmutable;
                    }
                }, new DateInterval('PT30S')),
            );
        } catch (RequiredConstraintsViolated $e) {
            throw new InvalidEventToken($e->getMessage());
        }

        $claims = $parsed->claims();

        if ($claims->get('scope') !== 'events.write' || ! is_string($claims->get('org')) || ! is_string($claims->get('sub'))) {
            throw new InvalidEventToken('not an event token');
        }

        $expiresAt = $claims->get('exp');

        return new EventToken(
            (string) $claims->get('sub'),
            strtolower((string) $claims->get('org')),
            (string) $claims->get('jti', ''),
            $expiresAt instanceof DateTimeImmutable ? $expiresAt : new DateTimeImmutable,
        );
    }
}
