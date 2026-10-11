<?php

namespace Miles\PlatformClient\SignIn;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
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
 * Authorization code + PKCE sign-in against the platform (docs/integration.md
 * section 1). State, nonce and the PKCE verifier stay in the product's own
 * session; the code is redeemed server-to-server and the id_token is
 * verified (signature from the JWKS, issuer, audience, time, nonce) before
 * anyone is signed in.
 */
final class SignIn
{
    private const SESSION_KEY = 'platform-client.pending';

    private const PENDING_TTL_SECONDS = 600;

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly Http $http,
        private readonly KeySet $keys,
    ) {}

    public function authorizationUrl(Request $request, string $redirectUri): string
    {
        $pending = [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => Str::random(64),
            'redirect_uri' => $redirectUri,
            'created_at' => now()->getTimestamp(),
        ];

        $request->session()->put(self::SESSION_KEY, $pending);

        return $this->config->issuer().'/oauth/authorize?'.http_build_query([
            'client_id' => $this->config->signInClientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $pending['state'],
            'nonce' => $pending['nonce'],
            'code_challenge' => self::base64Url(hash('sha256', $pending['verifier'], true)),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @throws SignInFailed
     */
    public function complete(Request $request): Identity
    {
        $pending = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($pending) || ! isset($pending['state'], $pending['nonce'], $pending['verifier'], $pending['redirect_uri'], $pending['created_at'])) {
            throw new SignInFailed('No pending sign-in for this session.');
        }

        if (now()->getTimestamp() - (int) $pending['created_at'] > self::PENDING_TTL_SECONDS) {
            throw new SignInFailed('The pending sign-in expired.');
        }

        $state = $request->query('state');
        if (! is_string($state) || ! hash_equals((string) $pending['state'], $state)) {
            throw new SignInFailed('State mismatch.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            $error = $request->query('error');

            throw new SignInFailed('Authorization failed: '.(is_string($error) ? $error : 'no code returned'));
        }

        $token = $this->verifyIdToken($this->redeemCode($code, (string) $pending['verifier'], (string) $pending['redirect_uri']));
        $claims = $token->claims();

        $nonce = $claims->get('nonce');
        if (! is_string($nonce) || ! hash_equals((string) $pending['nonce'], $nonce)) {
            throw new SignInFailed('Nonce mismatch.');
        }

        $subject = $claims->get('sub');
        if (! is_string($subject) || ! Str::isUuid($subject)) {
            throw new SignInFailed('id_token has no valid subject.');
        }

        return new Identity(
            subject: strtolower($subject),
            name: is_string($claims->get('name')) ? $claims->get('name') : '',
            email: is_string($claims->get('email')) ? $claims->get('email') : '',
            emailVerified: $claims->get('email_verified') === true,
        );
    }

    /**
     * @return non-empty-string
     */
    private function redeemCode(string $code, string $verifier, string $redirectUri): string
    {
        try {
            $response = $this->http->baseUrl($this->config->serverUrl())
                ->withHeaders($this->config->serverHeaders())
                ->asForm()
                ->acceptJson()
                ->withBasicAuth($this->config->signInClientId(), $this->config->signInClientSecret())
                ->timeout($this->config->httpTimeout())
                ->post('/oauth/token', [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $verifier,
                ]);
        } catch (ConnectionException $e) {
            throw new SignInFailed('Token endpoint unreachable: '.$e->getMessage(), previous: $e);
        }

        $idToken = $response->json('id_token');

        if (! $response->successful() || ! is_string($idToken) || $idToken === '') {
            $error = $response->json('error');

            throw new SignInFailed('Token request failed: HTTP '.$response->status().' '.(is_string($error) ? $error : ''));
        }

        return $idToken;
    }

    /**
     * @param  non-empty-string  $jwt
     */
    private function verifyIdToken(string $jwt): Plain
    {
        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText('-'),
            InMemory::plainText($this->keys->publicKey(Jwks::kidOf($jwt))),
        );

        try {
            $token = $config->parser()->parse($jwt);

            if (! $token instanceof Plain) {
                throw new SignInFailed('Unexpected id_token type.');
            }

            $config->validator()->assert(
                $token,
                new SignedWith($config->signer(), $config->verificationKey()),
                new IssuedBy($this->config->issuer()),
                new PermittedFor($this->config->signInClientId()),
                new LooseValidAt(self::clock(), new DateInterval('PT60S')),
            );
        } catch (SignInFailed $e) {
            throw $e;
        } catch (RequiredConstraintsViolated $e) {
            throw new SignInFailed('id_token rejected: '.$e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new SignInFailed('id_token unreadable: '.$e->getMessage(), previous: $e);
        }

        return $token;
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

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
