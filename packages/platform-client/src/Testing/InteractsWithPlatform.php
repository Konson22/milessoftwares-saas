<?php

namespace Miles\PlatformClient\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Str;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * For product feature tests (use in the TestCase):
 *
 *   $this->actingAsMember('acme', ['pharmacy.dispense']);
 *   $this->get($this->workspaceUrl('acme', '/dispensings'))->assertOk();
 *
 *   $this->asTenant('acme', fn () => Prescription::factory()->create());
 *
 * Fakes the platform API (no network) and signs the member in to that
 * workspace, exactly as a completed platform sign-in would.
 *
 * The API the mobile app calls (platform.api):
 *
 *   $this->actingAsAppMember('acme', ['pharmacy.sales.create']);
 *   $this->getJson($this->workspaceUrl('acme', '/api/v1/me'))->assertOk();
 *
 * @mixin TestCase
 */
trait InteractsWithPlatform
{
    private ?PlatformFake $platformFake = null;

    protected function platform(): PlatformFake
    {
        return $this->platformFake ??= (new PlatformFake)->install();
    }

    /**
     * Signs in a member of the workspace with these catalog permissions, at
     * organization scope or, with $branch, in that branch only. For several
     * roles at different scopes use platform()->grant() directly. The access
     * is in $application (default: the primary one), so a member can hold
     * access in one application of the deployment and not another.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $features  plan features the organization has (see EffectiveAccess::feature)
     */
    protected function actingAsMember(string $slug = 'acme', array $permissions = [], bool $owner = false, ?Authenticatable $user = null, ?string $branch = null, ?string $application = null, array $features = []): Authenticatable
    {
        $user ??= $this->platformUser();
        $scope = $branch === null ? ['scope_type' => 'organization'] : ['scope_type' => 'branch', 'branch_id' => $branch];
        $this->platform()->grant($slug, (string) $user->getAuthIdentifier(), $permissions === [] ? [] : [['permissions' => $permissions, 'scope' => $scope]], $owner, application: $application, features: $features);

        $this->actingAs($user);
        $this->withSession([RequirePlatformAccess::SESSION_TENANT => $this->platform()->organization($slug)['id']]);

        return $user;
    }

    /**
     * The same member calling the product's API from the mobile app: every
     * following request carries their access token (no session). The mobile
     * client is configured when the test has not set one. With $application
     * (a hosted one), the access is in it and the token is for its mobile
     * client.
     *
     * @param  list<string>  $permissions
     */
    protected function actingAsAppMember(string $slug = 'acme', array $permissions = [], bool $owner = false, ?Authenticatable $user = null, ?string $branch = null, ?string $application = null): Authenticatable
    {
        $config = app(PlatformConfig::class);
        $hosted = $application !== null && $application !== $config->primary();
        $client = $hosted
            ? $config->runAs($application, fn () => $config->mobileClientId())
            : $config->mobileClientId();
        if ($client === null) {
            $client = $hosted ? "{$application}-mobile-client" : 'mobile-client';
            config([$hosted ? "platform-client.hosted.{$application}.mobile.client_id" : 'platform-client.mobile.client_id' => $client]);
        }

        $user ??= $this->platformUser();
        $scope = $branch === null ? ['scope_type' => 'organization'] : ['scope_type' => 'branch', 'branch_id' => $branch];
        $this->platform()->grant($slug, (string) $user->getAuthIdentifier(), $permissions === [] ? [] : [['permissions' => $permissions, 'scope' => $scope]], $owner, application: $application);

        $this->withToken($this->platform()->appToken((string) $user->getAuthIdentifier(), $hosted ? $client : null));

        return $user;
    }

    /**
     * A local platform user (uuid key = platform subject).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function platformUser(array $attributes = []): Authenticatable
    {
        $model = app(PlatformConfig::class)->userModel();

        /** @var Model&Authenticatable $user */
        $user = new $model;
        $user->forceFill($attributes + [
            $user->getKeyName() => (string) Str::uuid(),
            'name' => 'Test Member',
            'email' => Str::random(8).'@example.test',
        ])->save();

        return $user;
    }

    /**
     * Run code inside a tenant (factories, assertions on tenant-owned data).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function asTenant(string $slug, callable $callback): mixed
    {
        return app(CurrentTenant::class)->run(Organization::fromArray($this->platform()->organization($slug)), $callback);
    }

    /**
     * A URL of this product in a workspace: workspaceUrl('acme', 'records')
     * is http://acme.<root>/<path>/records. With $application, under that
     * hosted application's path instead.
     */
    protected function workspaceUrl(string $slug, string $path = '', ?string $application = null): string
    {
        $config = app(PlatformConfig::class);
        $prefix = $config->runAs($application ?? $config->primary(), fn () => $config->path());

        return rtrim('http://'.$slug.'.'.$config->rootDomain().'/'.$prefix.'/'.ltrim($path, '/'), '/');
    }
}
