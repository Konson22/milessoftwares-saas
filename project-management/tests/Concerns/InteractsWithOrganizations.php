<?php

namespace Tests\Concerns;

use App\Access\Access;
use App\Access\Catalog;
use App\Actions\Organizations;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Hosts;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Miles\PlatformClient\Api\Organization as PlatformOrganization;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;
use Miles\PlatformClient\Testing\InteractsWithPlatform;

/**
 * Organizations, members and their permissions for feature tests: the
 * platform faked (miles/platform-client), and the copies this app keeps of
 * them, as a first visit would make them.
 */
trait InteractsWithOrganizations
{
    use InteractsWithPlatform {
        InteractsWithPlatform::actingAsMember as private actingAsPlatformMember;
        InteractsWithPlatform::asTenant as private asPlatformTenant;
    }

    /** @var array<string, true> */
    private array $madeOrganizations = [];

    /**
     * The organization with this slug, on the platform and here, made on
     * first use (USD, UTC).
     */
    protected function organization(string $slug): Organization
    {
        // USD and UTC the first time only: a test may change it on the platform after.
        $platform = isset($this->madeOrganizations[$slug])
            ? $this->platform()->organization($slug)
            : $this->platform()->organization($slug, ['display_name' => Str::headline($slug), 'default_currency' => 'USD', 'timezone' => 'UTC']);
        $this->madeOrganizations[$slug] = true;

        return Organization::query()->find($platform['id'])
            ?? Organizations::mirror(PlatformOrganization::fromArray($platform));
    }

    /**
     * A user who is a member of the organization, holding exactly these
     * permissions there, or every permission as its owner: on the platform,
     * and in the copy kept here.
     *
     * @param  list<string>  $permissions
     */
    protected function member(string $slug = 'acme', array $permissions = [], bool $owner = false, ?User $user = null): User
    {
        $organization = $this->organization($slug);
        $user ??= User::factory()->create();

        $this->platform()->grant($slug, $user->id, $permissions === [] ? [] : [['permissions' => $permissions, 'scope' => ['scope_type' => 'organization']]], $owner);
        // As the platform's webhook about the change would.
        app(PlatformApi::class)->forget($organization->id);

        $held = $owner ? Catalog::permissions() : array_values(array_unique($permissions));
        sort($held);
        Organizations::enter($organization, $user, new Access($organization->id, '', $owner, $held, $permissions === [] ? [] : ['role']));

        return $user;
    }

    /**
     * Signs in a member of the organization with these permissions, as a
     * completed platform sign-in on its workspace would.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $features
     */
    protected function actingAsMember(string $slug = 'acme', array $permissions = [], bool $owner = false, ?Authenticatable $user = null, ?string $branch = null, ?string $application = null, array $features = []): User
    {
        $user = $this->member($slug, $permissions, $owner, $user instanceof User ? $user : null);
        $this->actingAsPlatformMember($slug, $permissions, $owner, $user);

        return $user;
    }

    /**
     * Signs in someone already a member of the organization (member()), on
     * its workspace.
     */
    protected function signInTo(string $slug, User $user): User
    {
        $this->actingAs($user);
        $this->withSession(['platform-client.tenant_id' => $this->organization($slug)->id]);

        return $user;
    }

    /**
     * A signed-in user opening a workspace has signed in to it on the
     * platform (the session names that workspace), as after the platform's
     * sign-in. Whether they may use it is still the platform's answer.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $host = (string) parse_url($uri, PHP_URL_HOST);
        $root = '.'.Hosts::rootDomain();
        if (auth()->check() && str_ends_with($host, $root)) {
            $id = Organization::query()->where('slug', substr($host, 0, -strlen($root)))->value('id');
            if ($id !== null) {
                $this->withSession([RequirePlatformAccess::SESSION_TENANT => $id]);
            }
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Run code inside an organization (factories, assertions on its data).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function asTenant(string $slug, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->run($this->organization($slug), $callback);
    }

    /**
     * What an organization sent Finance (the platform outbox), oldest first,
     * each with its type and payload: financeEvents('acme', 'finance.cash.moved').
     *
     * @return list<array{type: string, transaction: string, payload: array<string, mixed>}>
     */
    protected function financeEvents(string $slug = 'acme', ?string $type = null): array
    {
        return DB::table('platform_outbox')
            ->where('organization_id', $this->organization($slug)->id)
            ->where('audience', 'finance')
            ->when($type !== null, fn ($query) => $query->where('event_type', $type))
            ->orderBy('id')
            ->get()
            ->map(fn (object $row) => [
                'type' => (string) $row->event_type,
                'transaction' => (string) $row->source_transaction_id,
                'payload' => json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR),
            ])
            ->values()
            ->all();
    }

    /**
     * The link that opens an organization: what emails and redirects carry.
     */
    protected function organizationLink(string $slug, string $path = '/'): string
    {
        return Hosts::organization($slug, Hosts::path().'/'.ltrim($path, '/'));
    }

    /**
     * A URL of Projects in an organization's workspace:
     * organizationUrl('acme', 'projects') is http://acme.milesoftwares.test/projects/projects.
     */
    protected function organizationUrl(string $slug, string $path = ''): string
    {
        $this->organization($slug);

        return $this->workspaceUrl($slug, $path);
    }
}
