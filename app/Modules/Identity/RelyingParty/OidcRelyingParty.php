<?php

namespace App\Modules\Identity\RelyingParty;

use App\Models\User;
use App\Support\Hosts\PlatformHosts;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Authorization code + PKCE sign-in for first-party hosts. State, nonce and
 * the PKCE verifier are kept in the host's own session; the code is redeemed
 * server-to-server and the id_token is verified before anyone is signed in.
 *
 * Tokens are not retained: first-party hosts read platform data directly and
 * only need the verified identity.
 */
final class OidcRelyingParty
{
    private const SESSION_KEY = 'oidc.pending';

    private const PENDING_TTL_SECONDS = 600;

    public function authorizationUrl(FirstPartyClient $client, Request $request): string
    {
        $pending = [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => Str::random(64),
            'created_at' => now()->getTimestamp(),
        ];

        $request->session()->put(self::SESSION_KEY, $pending);

        return PlatformHosts::url(PlatformHosts::auth(), 'oauth/authorize').'?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => $client->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $pending['state'],
            'nonce' => $pending['nonce'],
            'code_challenge' => self::base64Url(hash('sha256', $pending['verifier'], true)),
            'code_challenge_method' => 'S256',
            // Staff prove their credentials and MFA again for every admin session.
            ...($client->key === 'admin' ? ['prompt' => 'login'] : []),
        ]);
    }

    /**
     * @throws SignInFailed
     */
    public function completeSignIn(FirstPartyClient $client, Request $request): User
    {
        $pending = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($pending) || ! isset($pending['state'], $pending['nonce'], $pending['verifier'], $pending['created_at'])) {
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

        $idToken = $this->verifyIdToken($client, $this->redeemCode($client, $code, (string) $pending['verifier']));

        $nonce = $idToken->claims()->get('nonce');
        if (! is_string($nonce) || ! hash_equals((string) $pending['nonce'], $nonce)) {
            throw new SignInFailed('Nonce mismatch.');
        }

        $subject = $idToken->claims()->get('sub');
        if (! is_string($subject) || ! Str::isUuid($subject)) {
            throw new SignInFailed('id_token has no valid subject.');
        }

        $user = User::query()->find($subject);

        if ($user === null || $user->status !== 'active') {
            throw new SignInFailed('Subject is unknown or not active.');
        }

        return $user;
    }

    /**
     * @return non-empty-string
     */
    private function redeemCode(FirstPartyClient $client, string $code, string $verifier): string
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth($client->id, $client->secret)
                ->withHeaders($this->backchannelHeaders())
                ->timeout(10)
                ->post($this->tokenEndpoint(), [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $client->redirectUri(),
                    'code_verifier' => $verifier,
                ]);
        } catch (ConnectionException $e) {
            throw new SignInFailed('Token endpoint unreachable: '.$e->getMessage(), previous: $e);
        }

        $idToken = $response->json('id_token');

        if (! $response->ok() || ! is_string($idToken) || $idToken === '') {
            $error = $response->json('error');

            throw new SignInFailed('Token request failed: HTTP '.$response->status().' '.(is_string($error) ? $error : ''));
        }

        return $idToken;
    }

    /**
     * @param  non-empty-string  $jwt
     */
    private function verifyIdToken(FirstPartyClient $client, string $jwt): Plain
    {
        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText('-'),
            InMemory::plainText($this->publicKey()),
        );

        try {
            $token = $config->parser()->parse($jwt);

            if (! $token instanceof Plain) {
                throw new SignInFailed('Unexpected id_token type.');
            }

            $config->validator()->assert(
                $token,
                new SignedWith($config->signer(), $config->verificationKey()),
                new IssuedBy(PlatformHosts::url(PlatformHosts::auth())),
                new PermittedFor($client->id),
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

    /**
     * First-party hosts run in the same deployment as the provider, so they
     * verify against the provider's public key directly instead of JWKS.
     *
     * @return non-empty-string
     */
    private function publicKey(): string
    {
        $configured = config('passport.public_key');

        $path = Passport::keyPath('oauth-public.key');

        $key = match (true) {
            is_string($configured) && $configured !== '' => str_replace('\\n', "\n", $configured),
            is_file($path) => (string) file_get_contents($path),
            default => '',
        };

        if ($key === '') {
            throw new SignInFailed('The provider public key is not available.');
        }

        return $key;
    }

    private function tokenEndpoint(): string
    {
        $backchannel = config('platform.oidc.backchannel_url');

        return is_string($backchannel) && $backchannel !== ''
            ? rtrim($backchannel, '/').'/oauth/token'
            : PlatformHosts::url(PlatformHosts::auth(), 'oauth/token');
    }

    /**
     * The back-channel URL may be an internal address; auth host routes are
     * matched on the Host header, so it is sent explicitly.
     *
     * @return array<string, string>
     */
    private function backchannelHeaders(): array
    {
        $backchannel = config('platform.oidc.backchannel_url');

        return is_string($backchannel) && $backchannel !== '' ? ['Host' => PlatformHosts::auth()] : [];
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
