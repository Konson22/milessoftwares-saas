<?php

use App\Models\User;
use App\Modules\Applications\Support\WorkspaceShell;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Modules\Organizations\Actions\RenameOrganizationSlug;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

beforeEach(function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Workspace', [], confidential: true);
    WorkspaceShell::register((string) $client->getKey());

    config([
        'platform.oidc.clients.workspace.id' => $client->getKey(),
        'platform.oidc.clients.workspace.secret' => $client->plainSecret,
    ]);

    $this->owner = User::factory()->create();
    $this->organization = app(CreateOrganization::class)->handle($this->owner, [
        'display_name' => 'Manggala Trading', 'legal_name' => 'Manggala Trading Co. Ltd', 'slug' => 'manggala',
        'country_code' => 'SS', 'default_currency' => 'SSP', 'timezone' => 'Africa/Juba',
    ]);
});

function tenantUrl(string $slug, string $path = '/'): string
{
    return PlatformHosts::url(PlatformHosts::workspace($slug), $path);
}

/**
 * @return array<string, string>
 */
function startWorkspaceSignIn(string $slug): array
{
    $location = (string) test()->get(tenantUrl($slug, 'auth/login'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

function authorizeAs(User $user, array $query): TestResponse
{
    return test()->actingAs($user)->get(route('passport.authorizations.authorize').'?'.http_build_query($query));
}

function workspaceIdToken(User $user, string $nonce): string
{
    $config = Configuration::forAsymmetricSigner(
        new Sha256,
        InMemory::file(Passport::keyPath('oauth-private.key')),
        InMemory::file(Passport::keyPath('oauth-public.key')),
    );

    return $config->builder()
        ->issuedBy(PlatformHosts::url(PlatformHosts::auth()))
        ->permittedFor((string) config('platform.oidc.clients.workspace.id'))
        ->relatedTo($user->id)
        ->issuedAt(now()->toDateTimeImmutable())
        ->expiresAt(now()->addHour()->toDateTimeImmutable())
        ->withClaim('nonce', $nonce)
        ->getToken($config->signer(), $config->signingKey())
        ->toString();
}

it('starts the sign-in with this workspace as the callback', function () {
    $query = startWorkspaceSignIn('manggala');

    expect($query['client_id'])->toBe(config('platform.oidc.clients.workspace.id'))
        ->and($query['redirect_uri'])->toBe(tenantUrl('manggala', 'auth/callback'));

    $location = authorizeAs($this->owner, $query)->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith(tenantUrl('manggala', 'auth/callback').'?code=');
});

it('does not start a sign-in on an unknown workspace host', function () {
    $this->get(tenantUrl('nobody-here', 'auth/login'))->assertNotFound();
});

it('rejects a callback for another organization\'s workspace', function () {
    app(CreateOrganization::class)->handle(User::factory()->create(), [
        'display_name' => 'Beta', 'legal_name' => 'Beta Ltd', 'slug' => 'beta-co',
        'country_code' => 'SS', 'default_currency' => 'SSP', 'timezone' => 'Africa/Juba',
    ]);

    $query = startWorkspaceSignIn('manggala');
    $query['redirect_uri'] = 'https://attacker.example.test/auth/callback';

    authorizeAs($this->owner, $query)->assertStatus(401)->assertJsonPath('error', 'invalid_client');

    // A registered URI is fine, but only for an organization that exists.
    $query['redirect_uri'] = tenantUrl('beta-co', 'auth/callback');
    authorizeAs($this->owner, $query)->assertRedirect();

    $query['redirect_uri'] = tenantUrl('ghost-co', 'auth/callback');
    authorizeAs($this->owner, $query)->assertStatus(401);
});

it('stops accepting the old callback after a rename', function () {
    $this->travel(1)->minutes();
    app(RenameOrganizationSlug::class)->handle($this->organization, 'manggala-group', $this->owner);

    $query = startWorkspaceSignIn('manggala-group');
    authorizeAs($this->owner, $query)->assertRedirect();

    $query['redirect_uri'] = tenantUrl('manggala', 'auth/callback');
    authorizeAs($this->owner, $query)->assertStatus(401);
});

it('signs a member in on the workspace host after a valid callback', function () {
    $query = startWorkspaceSignIn('manggala');

    Http::fake([
        PlatformHosts::url(PlatformHosts::auth(), 'oauth/token') => Http::response([
            'token_type' => 'Bearer', 'access_token' => 'x', 'id_token' => workspaceIdToken($this->owner, $query['nonce']),
        ]),
    ]);

    $this->get(tenantUrl('manggala', 'auth/callback').'?'.http_build_query(['code' => 'c', 'state' => $query['state']]))
        ->assertRedirect('/')
        ->assertSessionHas('signed_in', true);

    $this->assertAuthenticatedAs($this->owner);
    $this->get(tenantUrl('manggala'))->assertOk();

    Http::assertSent(fn ($request) => ($request->data()['redirect_uri'] ?? null) === tenantUrl('manggala', 'auth/callback'));
});

it('signs non-members in but still forbids the workspace', function () {
    $outsider = User::factory()->create();
    $query = startWorkspaceSignIn('manggala');

    Http::fake([
        PlatformHosts::url(PlatformHosts::auth(), 'oauth/token') => Http::response([
            'id_token' => workspaceIdToken($outsider, $query['nonce']),
        ]),
    ]);

    $this->get(tenantUrl('manggala', 'auth/callback').'?'.http_build_query(['code' => 'c', 'state' => $query['state']]));

    $this->get(tenantUrl('manggala'))->assertForbidden();
    expect(OrganizationMembership::acrossTenants()->where('user_id', $outsider->id)->exists())->toBeFalse();
});

it('keeps one callback row per organization however many workspaces exist', function () {
    foreach (range(1, 5) as $i) {
        app(CreateOrganization::class)->handle(User::factory()->create(), [
            'display_name' => "Org {$i}", 'legal_name' => "Org {$i} Ltd", 'slug' => 'org-'.Str::lower(Str::random(6)),
            'country_code' => 'SS', 'default_currency' => 'SSP', 'timezone' => 'Africa/Juba',
        ]);
    }

    expect(Organization::query()->count())->toBe(6)
        ->and(DB::table('application_client_redirect_uris')->count())->toBe(6);
});
