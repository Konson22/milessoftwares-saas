<?php

namespace Miles\PlatformClient\Testing;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The platform API, faked at the HTTP layer for product tests: service
 * tokens, organization lookups, effective access, event tokens and health.
 * Use it through InteractsWithPlatform.
 */
final class PlatformFake
{
    /** @var array<string, array<string, mixed>> keyed by slug */
    public array $organizations = [];

    /** @var array<string, array<string, mixed>> keyed by "orgId:subject" */
    public array $access = [];

    /** @var list<array{organization_id: string, audience: string}> */
    public array $eventTokens = [];

    public function install(): self
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/oauth/token') {
                return Http::response(['token_type' => 'Bearer', 'access_token' => 'fake-service-token', 'expires_in' => 3600]);
            }

            if (preg_match('#^/api/v1/organizations/([^/]+)/members/([^/]+)/effective-access$#', $path, $m) === 1) {
                return isset($this->access[$m[1].':'.$m[2]])
                    ? Http::response($this->access[$m[1].':'.$m[2]])
                    : Http::response(['message' => 'Not Found'], 404);
            }

            if (preg_match('#^/api/v1/organizations/([^/]+)$#', $path, $m) === 1) {
                foreach ($this->organizations as $organization) {
                    if ($organization['slug'] === $m[1] || $organization['id'] === $m[1]) {
                        return Http::response($organization);
                    }
                }

                return Http::response(['message' => 'Not Found'], 404);
            }

            if ($path === '/api/v1/event-tokens') {
                $this->eventTokens[] = ['organization_id' => (string) $request['organization_id'], 'audience' => (string) $request['audience']];

                return Http::response(['token' => 'fake-event-token', 'expires_in' => 300]);
            }

            if ($path === '/api/v1/health') {
                return Http::response(['recorded' => true], 202);
            }

            return null;
        });

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function organization(string $slug, array $attributes = []): array
    {
        return $this->organizations[$slug] = $attributes + ($this->organizations[$slug] ?? [
            'id' => (string) Str::uuid(),
            'slug' => $slug,
            'display_name' => Str::headline($slug),
            'status' => 'active',
            'country_code' => 'SS',
            'default_currency' => 'SSP',
            'timezone' => 'Africa/Juba',
        ]);
    }

    /**
     * Effective access for a member: roles as [permissions, scope] pairs.
     *
     * @param  list<array{permissions: list<string>, scope?: array<string, string>, role?: string}>  $roles
     */
    public function grant(string $slug, string $subject, array $roles = [], bool $owner = false, bool $allowed = true): void
    {
        $organization = $this->organization($slug);

        $this->access[$organization['id'].':'.$subject] = [
            'allowed' => $allowed,
            'organization' => ['id' => $organization['id'], 'slug' => $slug, 'status' => $organization['status']],
            'application' => ['code' => (string) config('platform-client.application'), 'catalog_version' => '1.0.0'],
            'subject' => $subject,
            'entitlement' => ['active' => true, 'source' => 'subscription', 'valid_until' => null, 'features' => []],
            'membership' => ['id' => (string) Str::uuid(), 'status' => 'active', 'is_owner' => $owner],
            'roles' => array_map(fn (array $role) => [
                'role' => $role['role'] ?? 'role',
                'catalog_version' => '1.0.0',
                'scope' => $role['scope'] ?? ['scope_type' => 'organization'],
                'permissions' => $role['permissions'],
                'expires_at' => null,
            ], $roles),
            'max_age' => 60,
        ];
    }
}
