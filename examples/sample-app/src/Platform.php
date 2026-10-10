<?php

namespace SampleApp;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Everything a product does to integrate with the platform, in one class.
 *
 * @phpstan-type Config array{issuer: string, backchannel: string, client_id: string, client_secret: string, redirect_uri: string, service_client_id: string, service_client_secret: string, webhook_secret: string}
 */
final class Platform
{
    /**
     * @param  Config  $config
     */
    public function __construct(private readonly array $config, private readonly string $storage)
    {
        is_dir($storage) || mkdir($storage, 0775, true);
    }

    // ---- 1. Sign-in (OIDC authorization code + PKCE) -----------------------

    public function startLogin(string $organizationSlug): never
    {
        $verifier = bin2hex(random_bytes(32));
        $_SESSION['pending'] = [
            'state' => bin2hex(random_bytes(16)),
            'nonce' => bin2hex(random_bytes(16)),
            'verifier' => $verifier,
            'org' => preg_replace('/[^a-z0-9-]/', '', strtolower($organizationSlug)),
        ];

        $this->redirect($this->config['issuer'].'/oauth/authorize?'.http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $_SESSION['pending']['state'],
            'nonce' => $_SESSION['pending']['nonce'],
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function completeLogin(array $query): never
    {
        $pending = $_SESSION['pending'] ?? null;
        unset($_SESSION['pending']);

        if (! is_array($pending) || ! hash_equals($pending['state'], (string) ($query['state'] ?? ''))) {
            throw new RuntimeException('state mismatch');
        }

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => (string) ($query['code'] ?? ''),
            'redirect_uri' => $this->config['redirect_uri'],
            'code_verifier' => $pending['verifier'],
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ]);

        $idToken = $this->verifyIdToken((string) ($tokens['id_token'] ?? ''));

        if (! hash_equals($pending['nonce'], (string) $idToken->claims()->get('nonce', ''))) {
            throw new RuntimeException('nonce mismatch');
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'sub' => $idToken->claims()->get('sub'),
            'name' => $idToken->claims()->get('name'),
            'email' => $idToken->claims()->get('email'),
            'org' => $pending['org'],
        ];

        $this->redirect('/');
    }

    public function logout(): never
    {
        $_SESSION = [];
        session_destroy();
        $this->redirect('/');
    }

    // ---- 2. Effective access (client credentials) ---------------------------

    public function home(): never
    {
        $user = $_SESSION['user'] ?? null;

        if (! is_array($user)) {
            $this->respond(200, ['signed_in' => false, 'login' => '/login?org=<your-workspace-slug>']);
        }

        $token = $this->serviceToken('access.read');
        $organization = $this->get('/api/v1/organizations/'.$user['org'], $token);
        $access = $this->get("/api/v1/organizations/{$organization['id']}/members/{$user['sub']}/effective-access", $token);

        $this->respond(200, [
            'signed_in' => true,
            'user' => $user,
            'organization' => $organization['display_name'] ?? null,
            // A real product gates every request on this, then checks the
            // roles' scopes against the record being accessed.
            'allowed' => $access['allowed'] ?? false,
            'roles' => $access['roles'] ?? [],
            'entitlement' => $access['entitlement'] ?? null,
            'webhooks_received' => count(glob($this->storage.'/events/*.json') ?: []),
            'tenants_provisioned' => count(glob($this->storage.'/tenants/*.json') ?: []),
        ]);
    }

    // ---- 3. Webhooks --------------------------------------------------------

    public function receiveWebhook(): never
    {
        $body = (string) file_get_contents('php://input');
        $this->assertSigned($body);

        $event = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $id = preg_replace('/[^a-f0-9-]/', '', (string) ($event['event_id'] ?? ''));

        // Idempotent: the same event_id is processed once, however often it arrives.
        $file = "{$this->storage}/events/{$id}.json";
        is_dir(dirname($file)) || mkdir(dirname($file), 0775, true);
        if (! is_file($file)) {
            file_put_contents($file, $body);
            // A real product would now drop cached access for $event['organization_id'].
        }

        $this->respond(200, ['received' => $id]);
    }

    // ---- 4. Provisioning ----------------------------------------------------

    public function provisionTenant(): never
    {
        $body = (string) file_get_contents('php://input');
        $this->assertSigned($body);

        $key = preg_replace('/[^a-f0-9-]/', '', (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        if ($key === '') {
            $this->respond(400, ['error' => 'Idempotency-Key required']);
        }

        $file = "{$this->storage}/tenants/{$key}.json";
        is_dir(dirname($file)) || mkdir(dirname($file), 0775, true);
        if (! is_file($file)) {
            file_put_contents($file, $body);
        }

        $this->respond(201, ['tenant' => json_decode($body, true)['organization']['slug'] ?? null]);
    }

    // ---- helpers ------------------------------------------------------------

    private function verifyIdToken(string $jwt): Plain
    {
        if ($jwt === '') {
            throw new RuntimeException('no id_token');
        }

        // Read the key id first (unverified), then verify with that key.
        $unverified = (new Parser(new JoseEncoder))->parse($jwt);
        assert($unverified instanceof Plain);
        $kid = $unverified->headers()->get('kid');

        $keys = $this->get('/oauth/jwks')['keys'] ?? [];
        $jwk = null;
        foreach ($keys as $candidate) {
            if (($candidate['kid'] ?? null) === $kid) {
                $jwk = $candidate;
            }
        }

        if ($jwk === null) {
            throw new RuntimeException('signing key not in JWKS');
        }

        $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText('-'), InMemory::plainText(self::jwkToPem($jwk)));
        $token = $config->parser()->parse($jwt);
        assert($token instanceof Plain);

        $config->validator()->assert(
            $token,
            new SignedWith($config->signer(), $config->verificationKey()),
            new IssuedBy($this->config['issuer']),
            new PermittedFor($this->config['client_id']),
            new LooseValidAt(new class implements ClockInterface
            {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable;
                }
            }, new \DateInterval('PT60S')),
        );

        return $token;
    }

    private function assertSigned(string $body): void
    {
        $header = (string) ($_SERVER['HTTP_MILES_SIGNATURE'] ?? '');
        parse_str(str_replace(',', '&', $header), $parts);
        $timestamp = (int) ($parts['t'] ?? 0);

        $valid = abs(time() - $timestamp) <= 300
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $this->config['webhook_secret']), (string) ($parts['v1'] ?? ''));

        if (! $valid) {
            $this->respond(401, ['error' => 'bad signature']);
        }
    }

    private function serviceToken(string $scope): string
    {
        return (string) $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->config['service_client_id'],
            'client_secret' => $this->config['service_client_secret'],
            'scope' => $scope,
        ])['access_token'];
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private function post(string $path, array $form): array
    {
        return $this->http('POST', $path, http_build_query($form), ['Content-Type: application/x-www-form-urlencoded']);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path, ?string $token = null): array
    {
        return $this->http('GET', $path, null, $token === null ? [] : ['Authorization: Bearer '.$token]);
    }

    /**
     * Server-to-server calls go to the back-channel address with the auth
     * host's Host header (the platform routes by host).
     *
     * @param  list<string>  $headers
     * @return array<string, mixed>
     */
    private function http(string $method, string $path, ?string $body, array $headers): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", [...$headers, 'Accept: application/json', 'Host: '.parse_url($this->config['issuer'], PHP_URL_HOST)]),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);

        $response = file_get_contents(rtrim($this->config['backchannel'], '/').$path, false, $context);
        $status = (int) (explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1] ?? 0);
        $json = json_decode((string) $response, true);

        if ($status >= 400 || ! is_array($json)) {
            throw new RuntimeException("{$method} {$path} failed: HTTP {$status} ".substr((string) $response, 0, 200));
        }

        return $json;
    }

    /**
     * @param  array{n: string, e: string}  $jwk
     */
    private static function jwkToPem(array $jwk): string
    {
        $modulus = self::base64UrlDecode($jwk['n']);
        $exponent = self::base64UrlDecode($jwk['e']);

        $integer = fn (string $bytes) => "\x02".self::derLength(strlen(ltrim($bytes, "\x00")) + (ord(ltrim($bytes, "\x00")[0]) > 0x7F ? 1 : 0))
            .(ord(ltrim($bytes, "\x00")[0]) > 0x7F ? "\x00" : '').ltrim($bytes, "\x00");

        $rsaKey = $integer($modulus).$integer($exponent);
        $rsaKey = "\x30".self::derLength(strlen($rsaKey)).$rsaKey;
        $bitString = "\x03".self::derLength(strlen($rsaKey) + 1)."\x00".$rsaKey;
        $algorithm = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $spki = "\x30".self::derLength(strlen($algorithm.$bitString)).$algorithm.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    public function redirect(string $location): never
    {
        header('Location: '.$location, true, 302);
        exit;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function respond(int $status, array $data): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
