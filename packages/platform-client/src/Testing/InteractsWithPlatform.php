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
     * Signs in a member of the workspace with these catalog permissions at
     * organization scope (or pass roles with scopes to grant()).
     *
     * @param  list<string>  $permissions
     */
    protected function actingAsMember(string $slug = 'acme', array $permissions = [], bool $owner = false, ?Authenticatable $user = null): Authenticatable
    {
        $user ??= $this->platformUser();
        $this->platform()->grant($slug, (string) $user->getAuthIdentifier(), $permissions === [] ? [] : [['permissions' => $permissions]], $owner);

        $this->actingAs($user);
        $this->withSession([RequirePlatformAccess::SESSION_TENANT => $this->platform()->organization($slug)['id']]);

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
     * is http://acme.<root>/<path>/records.
     */
    protected function workspaceUrl(string $slug, string $path = ''): string
    {
        $config = app(PlatformConfig::class);

        return rtrim('http://'.$slug.'.'.$config->rootDomain().'/'.$config->path().'/'.ltrim($path, '/'), '/');
    }
}
