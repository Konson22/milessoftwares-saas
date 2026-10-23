<?php

namespace Miles\PlatformClient\SignIn;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Miles\PlatformClient\PlatformConfig;
use Miles\SharedModuleKit\Jwks;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Tokens the mobile app signed in with (authorization code + PKCE on the
 * device, against the platform's public mobile client):
 *
 * - access tokens, sent as "Authorization: Bearer" on every API call. The
 *   platform signs them with its OpenID key (the JWKS) for the client that
 *   asked: audience = the mobile client, subject = the platform user. They
 *   carry no issuer and live ten minutes, refreshed by the app.
 * - id tokens, sent once to start a session: the user's name and email.
 *
 * Revocation is not looked up: a token lives ten minutes, and access to
 * the workspace is checked on every request (RequirePlatformToken).
 */
final class AppTokens
{
    public function __construct(
        private readonly PlatformConfig $config,
        private readonly KeySet $keys,
    ) {}

    /**
     * The platform user an access token was issued to.
     *
     * @throws SignInFailed
     */
    public function subject(string $accessToken): string
    {
        return self::subjectOf($this->verify($accessToken, issued: false));
    }

    /**
     * The user an id token names, verified: signature, issuer, audience, time.
     *
     * @throws SignInFailed
     */
    public function identity(string $idToken): Identity
    {
        $token = $this->verify($idToken, issued: true);
        $claims = $token->claims();

        return new Identity(
            subject: self::subjectOf($token),
            name: is_string($claims->get('name')) ? $claims->get('name') : '',
            email: is_string($claims->get('email')) ? $claims->get('email') : '',
            emailVerified: $claims->get('email_verified') === true,
        );
    }

    private function verify(string $jwt, bool $issued): Plain
    {
        $client = $this->config->mobileClientId();
        if ($client === null || $jwt === '') {
            throw new SignInFailed('No mobile client or no token.');
        }

        try {
            $config = Configuration::forAsymmetricSigner(
                new Sha256,
                InMemory::plainText('-'),
                InMemory::plainText($this->keys->publicKey(Jwks::kidOf($jwt))),
            );

            $token = $config->parser()->parse($jwt);
            if (! $token instanceof Plain) {
                throw new SignInFailed('Unexpected token type.');
            }

            $constraints = [
                new SignedWith($config->signer(), $config->verificationKey()),
                new PermittedFor($client),
                new LooseValidAt(self::clock(), new DateInterval('PT60S')),
            ];
            if ($issued) {
                $constraints[] = new IssuedBy($this->config->issuer());
            }

            $config->validator()->assert($token, ...$constraints);

            // Loose validity accepts a token without expiry; these always have one.
            if (! $token->claims()->has('exp')) {
                throw new SignInFailed('Token never expires.');
            }
        } catch (SignInFailed $e) {
            throw $e;
        } catch (RequiredConstraintsViolated $e) {
            throw new SignInFailed('Token rejected: '.$e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new SignInFailed('Token unreadable: '.$e->getMessage(), previous: $e);
        }

        return $token;
    }

    private static function subjectOf(Plain $token): string
    {
        $subject = $token->claims()->get('sub');
        if (! is_string($subject) || ! Str::isUuid($subject)) {
            throw new SignInFailed('Token has no valid subject.');
        }

        return strtolower($subject);
    }

    private static function clock(): ClockInterface
    {
        return new class implements ClockInterface
        {
            public function now(): DateTimeImmutable
            {
                return now()->toDateTimeImmutable();
            }
        };
    }
}
