<?php

namespace Miles\PlatformClient\Testing;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Miles\PlatformClient\PlatformConfig;

/**
 * The platform API, faked at the HTTP layer for product tests: service
 * tokens, organization lookups, effective access, event tokens and health.
 * Use it through InteractsWithPlatform.
 */
final class PlatformFake
{
    /** @var array<string, array<string, mixed>> keyed by slug */
    public array $organizations = [];

    /** @var array<string, array<string, mixed>> keyed by "application:orgId:subject" */
    public array $access = [];

    /** @var array<string, list<string>> slugs an application may not see (not entitled), by application */
    public array $hiddenFrom = [];

    /** @var array<string, list<array<string, mixed>>> keyed by slug */
    public array $branches = [];

    /** @var array<string, list<array<string, mixed>>> keyed by slug */
    public array $departments = [];

    /** @var array<string, list<array<string, mixed>>> keyed by slug */
    public array $positions = [];

    /** @var list<array{organization_id: string, audience: string, application: string}> */
    public array $eventTokens = [];

    /** @var array<string, list<array<string, mixed>>> envelopes received, by module */
    public array $moduleEvents = [];

    /** @var array<string, list<int>> statuses the next deliveries get, by module (default 201) */
    public array $moduleAnswers = [];

    /** @var list<string> modules the organization is not entitled to: event tokens answer 404 */
    public array $notEntitledTo = [];

    public function install(): self
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/oauth/token') {
                // One token per service client: later calls tell the application by it.
                return Http::response(['token_type' => 'Bearer', 'access_token' => 'fake-service-token:'.$request['client_id'], 'expires_in' => 3600]);
            }

            if (preg_match('#^/api/v1/organizations/([^/]+)/members/([^/]+)/effective-access$#', $path, $m) === 1) {
                return isset($this->access[$this->caller($request).':'.$m[1].':'.$m[2]])
                    ? Http::response($this->access[$this->caller($request).':'.$m[1].':'.$m[2]])
                    : Http::response(['message' => 'Not Found'], 404);
            }

            if (preg_match('#^/api/v1/organizations/([^/]+)/(branches|departments|positions)$#', $path, $m) === 1) {
                foreach ($this->organizations as $slug => $organization) {
                    if ($organization['id'] === $m[1] && $this->visible($request, $slug)) {
                        return Http::response(['data' => $this->{$m[2]}[$slug] ?? []]);
                    }
                }

                return Http::response(['message' => 'Not Found'], 404);
            }

            if (preg_match('#^/api/v1/organizations/([^/]+)$#', $path, $m) === 1) {
                foreach ($this->organizations as $slug => $organization) {
                    if (($organization['slug'] === $m[1] || $organization['id'] === $m[1]) && $this->visible($request, $slug)) {
                        return Http::response($organization);
                    }
                }

                return Http::response(['message' => 'Not Found'], 404);
            }

            foreach ((array) config('platform-client.modules') as $module => $url) {
                if (is_string($url) && $url !== '' && $request->url() === $url) {
                    $this->moduleEvents[$module][] = $request->data();
                    $this->moduleAnswers[$module] ??= [];
                    $status = array_shift($this->moduleAnswers[$module]) ?? 201;

                    return Http::response(['status' => $status], $status);
                }
            }

            if ($path === '/api/v1/event-tokens') {
                if (in_array((string) $request['audience'], $this->notEntitledTo, true)) {
                    return Http::response(['error' => 'not_entitled'], 404);
                }
                $this->eventTokens[] = ['organization_id' => (string) $request['organization_id'], 'audience' => (string) $request['audience'], 'application' => $this->caller($request)];

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
     * Adds a branch to the organization; returns its id.
     */
    public function branch(string $slug, string $name, ?string $code = null): string
    {
        $this->organization($slug);
        $id = (string) Str::uuid();
        $this->branches[$slug][] = ['id' => $id, 'name' => $name, 'code' => $code ?? strtoupper(substr($name, 0, 3)), 'city' => $name, 'status' => 'active'];

        return $id;
    }

    /**
     * Adds a department to the organization (company-wide without a branch); returns its id.
     */
    public function department(string $slug, string $name, ?string $branchId = null, ?string $code = null): string
    {
        $this->organization($slug);
        $id = (string) Str::uuid();
        $this->departments[$slug][] = ['id' => $id, 'branch_id' => $branchId, 'parent_department_id' => null, 'name' => $name, 'code' => $code ?? strtoupper(substr($name, 0, 3)), 'status' => 'active'];

        return $id;
    }

    /**
     * Adds a position (job title) to the organization; returns its id.
     */
    public function position(string $slug, string $title, ?string $departmentId = null, ?string $code = null): string
    {
        $this->organization($slug);
        $id = (string) Str::uuid();
        $this->positions[$slug][] = ['id' => $id, 'department_id' => $departmentId, 'title' => $title, 'code' => $code ?? strtoupper(substr($title, 0, 3)), 'status' => 'active'];

        return $id;
    }

    /**
     * Effective access for a member: roles as [permissions, scope] pairs,
     * in $application (default: the one being served, see PlatformConfig).
     *
     * @param  list<array{permissions: list<string>, scope?: array<string, string>, role?: string}>  $roles
     */
    public function grant(string $slug, string $subject, array $roles = [], bool $owner = false, bool $allowed = true, ?string $application = null): void
    {
        $organization = $this->organization($slug);
        $application ??= app(PlatformConfig::class)->application();

        $this->access[$application.':'.$organization['id'].':'.$subject] = [
            'allowed' => $allowed,
            'organization' => ['id' => $organization['id'], 'slug' => $slug, 'status' => $organization['status']],
            'application' => ['code' => $application, 'catalog_version' => '1.0.0'],
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

    /**
     * The application whose service client made the request: tokens are
     * "fake-service-token:<client id>".
     */
    private function caller(Request $request): string
    {
        $client = substr((string) ($request->header('Authorization')[0] ?? ''), strlen('Bearer fake-service-token:'));
        $config = app(PlatformConfig::class);

        foreach ($config->applications() as $application) {
            if ($config->runAs($application, fn () => $config->serviceClientId()) === $client) {
                return $application;
            }
        }

        return $config->primary();
    }

    private function visible(Request $request, string $slug): bool
    {
        return ! in_array($slug, $this->hiddenFrom[$this->caller($request)] ?? [], true);
    }
}
