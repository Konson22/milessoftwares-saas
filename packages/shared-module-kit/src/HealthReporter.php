<?php

namespace Miles\SharedModuleKit;

use RuntimeException;

/**
 * Reports a product's or module's health to the platform
 * (POST /api/v1/health with a client-credentials token). Call it on a
 * schedule, e.g. every minute; the platform shows a service as unknown once
 * its last report is more than five minutes old.
 */
final class HealthReporter
{
    /**
     * @param  string  $platformUrl  the auth host, e.g. https://auth.milesoftwares.com
     * @param  string|null  $hostHeader  set when calling an internal back-channel address
     */
    public function __construct(
        private readonly string $platformUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $hostHeader = null,
    ) {}

    /**
     * @param  'ok'|'degraded'|'down'  $status
     * @param  array<string, 'ok'|'degraded'|'down'>  $components  e.g. ['database' => 'ok', 'queue' => 'degraded']
     */
    public function report(string $status, array $components = [], ?string $version = null, ?string $message = null): void
    {
        $token = $this->request('POST', '/oauth/token', http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'health.write',
        ]), 'application/x-www-form-urlencoded')['access_token'] ?? null;

        if (! is_string($token)) {
            throw new RuntimeException('Could not obtain a platform token for health reporting.');
        }

        $this->request('POST', '/api/v1/health', json_encode(array_filter([
            'status' => $status,
            'components' => $components,
            'version' => $version,
            'message' => $message,
        ], fn ($value) => $value !== null), JSON_THROW_ON_ERROR), 'application/json', $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, string $body, string $contentType, ?string $token = null): array
    {
        $headers = ["Content-Type: {$contentType}", 'Accept: application/json'];
        if ($token !== null) {
            $headers[] = "Authorization: Bearer {$token}";
        }
        if ($this->hostHeader !== null) {
            $headers[] = "Host: {$this->hostHeader}";
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);

        $response = file_get_contents(rtrim($this->platformUrl, '/').$path, false, $context);
        $status = (int) (explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1] ?? 0);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("{$method} {$path} failed with HTTP {$status}");
        }

        $json = json_decode((string) $response, true);

        return is_array($json) ? $json : [];
    }
}
