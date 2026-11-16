<?php

namespace Miles\PlatformClient\Integration;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformRefused;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\PlatformConfig;

/**
 * Reads a paired product's integration API, server to server (ADR-0019 §9:
 * Projects and M&E). Live reads only: nothing is replicated or cached here.
 *
 *   - A short-lived read token from the platform for the organization (and
 *     the acting member, when there is one). The platform refuses it unless
 *     the organization is active and entitled to both products.
 *   - GET only, so a retry is always safe: one retry after a connection
 *     failure or a 502/503/504, never after an answer.
 *   - 3 s to answer (2 s to connect): a page that shows linked data never
 *     waits long for the other product.
 *   - X-Correlation-Id on the request; both sides log it.
 *   - Every outcome comes back as an IntegrationStatus, never an exception
 *     and never an empty "nothing there" when the read did not happen.
 */
final class IntegrationClient
{
    public const TIMEOUT_SECONDS = 3;

    public const CONNECT_TIMEOUT_SECONDS = 2;

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PlatformApi $platform,
        private readonly Http $http,
    ) {}

    public function configured(string $audience): bool
    {
        return $this->config->integrationUrl($audience) !== null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $audience, string $organizationId, string $path, array $query = [], ?string $actingUser = null, ?string $correlationId = null): IntegrationResult
    {
        $correlationId ??= (string) Str::uuid7();
        $started = microtime(true);
        $result = $this->read($audience, $organizationId, $path, $query, $actingUser, $correlationId);

        $context = [
            'audience' => $audience,
            'organization' => $organizationId,
            'path' => $path,
            'acting_user' => $actingUser,
            'status' => $result->status->value,
            'error' => $result->error,
            'correlation_id' => $correlationId,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        $result->ok() ? Log::info('Integration read', $context) : Log::warning('Integration read failed', $context);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function read(string $audience, string $organizationId, string $path, array $query, ?string $actingUser, string $correlationId): IntegrationResult
    {
        $base = $this->config->integrationUrl($audience);
        if ($base === null) {
            return $this->result(IntegrationStatus::NotConfigured, $correlationId, error: 'not_configured');
        }

        try {
            $token = $this->platform->integrationToken($organizationId, $audience, $actingUser);
        } catch (NotVisible) {
            return $this->result(IntegrationStatus::NotEntitled, $correlationId, error: 'not_entitled');
        } catch (PlatformRefused $e) {
            // An expired subscription is a lack of entitlement, said as such.
            return $e->getMessage() === 'entitlement_expired'
                ? $this->result(IntegrationStatus::NotEntitled, $correlationId, error: 'entitlement_expired')
                : $this->result(IntegrationStatus::Denied, $correlationId, error: $e->getMessage());
        } catch (PlatformUnavailable) {
            return $this->result(IntegrationStatus::Unavailable, $correlationId, error: 'platform_unavailable');
        }

        $url = rtrim($base, '/').'/'.ltrim($path, '/');
        $request = fn () => $this->http
            ->withToken($token)
            ->withHeaders(['X-Correlation-Id' => $correlationId])
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->get($url, $query);

        try {
            $response = $request();
            if (in_array($response->status(), [502, 503, 504], true)) {
                usleep(200_000);
                $response = $request();
            }
        } catch (ConnectionException $e) {
            if (self::timedOut($e)) {
                return $this->result(IntegrationStatus::Timeout, $correlationId, error: 'timeout');
            }
            try {
                usleep(200_000);
                $response = $request();
            } catch (ConnectionException $again) {
                return $this->result(self::timedOut($again) ? IntegrationStatus::Timeout : IntegrationStatus::Unavailable, $correlationId, error: self::timedOut($again) ? 'timeout' : 'unreachable');
            }
        }

        return $this->classify($response, $correlationId);
    }

    private function classify(Response $response, string $correlationId): IntegrationResult
    {
        $body = $response->json();
        $data = is_array($body) ? $body : [];
        $error = is_string($data['error'] ?? null) ? $data['error'] : null;

        $status = match (true) {
            $response->successful() => IntegrationStatus::Ok,
            $response->status() === 401, $response->status() === 403 => IntegrationStatus::Denied,
            $response->status() === 404 => IntegrationStatus::NotFound,
            in_array($response->status(), [400, 409, 422], true) => IntegrationStatus::Invalid,
            default => IntegrationStatus::Unavailable,
        };

        if ($status === IntegrationStatus::Ok && ! is_array($body)) {
            return $this->result(IntegrationStatus::Unavailable, $correlationId, error: 'unreadable_answer');
        }

        /** @var array<string, mixed> $data */
        return $this->result($status, $correlationId, $status === IntegrationStatus::Ok ? $data : [], $error ?? ($status === IntegrationStatus::Ok ? null : 'http_'.$response->status()));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function result(IntegrationStatus $status, string $correlationId, array $data = [], ?string $error = null): IntegrationResult
    {
        return new IntegrationResult($status, $data, $error, $correlationId, new DateTimeImmutable);
    }

    private static function timedOut(ConnectionException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');
    }
}
