<?php

use App\Models\User;
use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Integration\Actions\ManageIntegration;
use App\Modules\Memberships\Models\Invitation;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Notifications\OrganizationInvitation;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

const MOBILE_CALLBACK = 'com.milesoftwares.retail://oauth';

beforeEach(function () {
    Notification::fake();

    $importer = app(CatalogImporter::class);
    $importer->import(json_decode((string) file_get_contents(database_path('catalogs/platform.json')), true));
    $importer->import(json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true));
    $this->retail = Application::query()->where('code', 'retail')->sole();
    $this->retail->forceFill(['status' => 'available', 'trial_enabled' => true])->save();

    $this->mobile = app(ManageIntegration::class)->registerSignInClient($this->retail, 'Retail mobile', false, [MOBILE_CALLBACK], null, null);

    // An owner, whose business was created from the app (with the app's trial and a first branch).
    $this->owner = User::factory()->create();
    $this->ownerToken = mobileToken($this->owner, $this->mobile['client_id']);
    $this->withToken($this->ownerToken)->postJson(mobileApiUrl('organizations'), [
        'display_name' => 'Gudele Shop',
        'legal_name' => 'Gudele Shop Ltd',
        'slug' => 'gudele',
        'country_code' => 'ss',
        'default_currency' => 'ssp',
        'timezone' => 'Africa/Juba',
        'branch_name' => 'Gudele',
        'branch_city' => 'Juba',
    ])->assertCreated();
    $this->organization = Organization::query()->sole();
    $this->branch = app(TenantContext::class)->run($this->organization, fn () => Branch::query()->sole());
});

/** A person's access token from the app's public client (authorization code + PKCE). */
function mobileToken(User $user, string $clientId): string
{
    $verifier = Str::random(64);
    $location = (string) test()->actingAs($user)->get(route('passport.authorizations.authorize').'?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => MOBILE_CALLBACK,
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'state' => Str::random(32),
        'nonce' => Str::random(32),
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $callback);

    $token = test()->post(route('passport.token'), [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => MOBILE_CALLBACK,
        'code' => $callback['code'],
        'code_verifier' => $verifier,
    ])->assertOk()->json('access_token');

    auth()->guard('web')->logout();

    return (string) $token;
}

function mobileApiUrl(string $path): string
{
    return PlatformHosts::url(PlatformHosts::auth(), 'api/v1/account/'.ltrim($path, '/'));
}

it('lists the app\'s roles, the branches and the invitations', function () {
    $this->withToken($this->ownerToken)->getJson(mobileApiUrl('organizations/gudele/invitations'))
        ->assertOk()
        ->assertJsonPath('invitations', [])
        ->assertJsonPath('branches.0.id', $this->branch->id)
        ->assertJsonPath('roles.0.name', 'Cashier')
        ->assertJsonPath('roles.0.scopes', ['branch']);
});

it('invites someone with a role at a branch, by email', function () {
    $cashier = $this->withToken($this->ownerToken)->getJson(mobileApiUrl('organizations/gudele/invitations'))->json('roles.0.id');

    $this->withToken($this->ownerToken)->postJson(mobileApiUrl('organizations/gudele/invitations'), [
        'email' => 'Akol@Example.com',
        'role_ids' => [$cashier],
        'scope_type' => 'branch',
        'branch_id' => $this->branch->id,
    ])->assertCreated()->assertJsonPath('invitation.email', 'akol@example.com');

    Notification::assertSentTo(new AnonymousNotifiable, OrganizationInvitation::class);

    $this->withToken($this->ownerToken)->getJson(mobileApiUrl('organizations/gudele/invitations'))
        ->assertOk()
        ->assertJsonPath('invitations.0.email', 'akol@example.com')
        ->assertJsonPath('invitations.0.status', 'pending')
        ->assertJsonPath('invitations.0.roles', ['Cashier']);
});

it('resends and revokes an invitation', function () {
    $this->withToken($this->ownerToken)->postJson(mobileApiUrl('organizations/gudele/invitations'), ['email' => 'akol@example.com'])->assertCreated();

    $id = app(TenantContext::class)->run($this->organization, fn () => Invitation::query()->sole()->id);

    $this->withToken($this->ownerToken)->postJson(mobileApiUrl("organizations/gudele/invitations/{$id}/resend"))->assertOk();
    $this->withToken($this->ownerToken)->deleteJson(mobileApiUrl("organizations/gudele/invitations/{$id}"))->assertOk();

    expect(app(TenantContext::class)->run($this->organization, fn () => Invitation::query()->sole()->status))->toBe('revoked');
});

it('refuses roles of other applications', function () {
    $platformRole = ApplicationRole::query()->where('catalog_version_id', '!=', $this->retail->current_catalog_version_id)->first();
    if ($platformRole === null) {
        $this->markTestSkipped('No other application role in the catalog.');
    }

    $this->withToken($this->ownerToken)->postJson(mobileApiUrl('organizations/gudele/invitations'), [
        'email' => 'akol@example.com',
        'role_ids' => [$platformRole->id],
        'scope_type' => 'organization',
    ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');
});

it('is for members who manage access only', function () {
    $outsider = User::factory()->create();
    $this->withToken(mobileToken($outsider, $this->mobile['client_id']))
        ->getJson(mobileApiUrl('organizations/gudele/invitations'))->assertNotFound();

    $member = User::factory()->create();
    app(TenantContext::class)->run($this->organization, fn () => OrganizationMembership::query()->forceCreate([
        'user_id' => $member->id,
        'status' => 'active',
    ]));
    $this->withToken(mobileToken($member, $this->mobile['client_id']))
        ->postJson(mobileApiUrl('organizations/gudele/invitations'), ['email' => 'akol@example.com'])->assertForbidden();

    $this->withToken($this->ownerToken)->getJson(mobileApiUrl('organizations/nowhere/invitations'))->assertNotFound();
});

it('refuses while the business is suspended', function () {
    $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

    $this->withToken($this->ownerToken)->postJson(mobileApiUrl('organizations/gudele/invitations'), ['email' => 'akol@example.com'])
        ->assertForbidden()->assertJsonPath('error', 'organization_suspended');
});

it('refuses requests without a valid token', function () {
    $this->flushHeaders()->getJson(mobileApiUrl('organizations/gudele/invitations'))->assertUnauthorized();
});

it('adds a branch from the app, with a code from its name', function () {
    $this->withToken($this->ownerToken)->postJson(mobileApiUrl('organizations/gudele/branches'), ['name' => 'Juba Town', 'city' => 'Juba'])
        ->assertCreated()
        ->assertJsonPath('branch.name', 'Juba Town')
        ->assertJsonPath('branch.code', 'JUBA-TOWN')
        ->assertJsonPath('branch.city', 'Juba');

    $added = app(TenantContext::class)->run($this->organization, fn () => Branch::query()->where('code', 'JUBA-TOWN')->sole());
    expect($added->country_code)->toBe('SS')->and($added->status)->toBe('active');

    // The same name again, one with no latin letters: still a code of their own.
    $this->withToken($this->ownerToken)->postJson(mobileApiUrl('organizations/gudele/branches'), ['name' => 'Juba Town'])
        ->assertCreated()->assertJsonPath('branch.code', 'JUBA-TOWN-2');
    $this->withToken($this->ownerToken)->postJson(mobileApiUrl('organizations/gudele/branches'), ['name' => 'جوبا'])
        ->assertCreated();

    $this->withToken($this->ownerToken)->postJson(mobileApiUrl('organizations/gudele/branches'), ['name' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('adds branches for those who manage the structure only', function () {
    $outsider = User::factory()->create();
    $this->withToken(mobileToken($outsider, $this->mobile['client_id']))
        ->postJson(mobileApiUrl('organizations/gudele/branches'), ['name' => 'Wau'])->assertNotFound();

    $member = User::factory()->create();
    app(TenantContext::class)->run($this->organization, fn () => OrganizationMembership::query()->forceCreate([
        'user_id' => $member->id,
        'status' => 'active',
    ]));
    $this->withToken(mobileToken($member, $this->mobile['client_id']))
        ->postJson(mobileApiUrl('organizations/gudele/branches'), ['name' => 'Wau'])->assertForbidden();

    $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
    $this->withToken($this->ownerToken)->postJson(mobileApiUrl('organizations/gudele/branches'), ['name' => 'Wau'])
        ->assertForbidden()->assertJsonPath('error', 'organization_suspended');

    expect(app(TenantContext::class)->run($this->organization, fn () => Branch::query()->count()))->toBe(1);
});

it('gives the app a new id token when it refreshes, to open another workspace', function () {
    $user = User::factory()->create();
    $verifier = Str::random(64);
    $location = (string) $this->actingAs($user)->get(route('passport.authorizations.authorize').'?'.http_build_query([
        'client_id' => $this->mobile['client_id'],
        'redirect_uri' => MOBILE_CALLBACK,
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'state' => Str::random(32),
        'nonce' => Str::random(32),
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $callback);
    $first = $this->post(route('passport.token'), [
        'grant_type' => 'authorization_code',
        'client_id' => $this->mobile['client_id'],
        'redirect_uri' => MOBILE_CALLBACK,
        'code' => $callback['code'],
        'code_verifier' => $verifier,
    ])->assertOk();

    $refreshed = $this->post(route('passport.token'), [
        'grant_type' => 'refresh_token',
        'client_id' => $this->mobile['client_id'],
        'refresh_token' => $first->json('refresh_token'),
        'scope' => 'openid profile email',
    ])->assertOk();

    $claims = json_decode(base64_decode(strtr(explode('.', (string) $refreshed->json('id_token'))[1], '-_', '+/')), true);
    expect($claims['sub'])->toBe($user->id)
        ->and($claims['aud'])->toBe($this->mobile['client_id'])
        ->and($claims['email'])->toBe($user->email);
});
