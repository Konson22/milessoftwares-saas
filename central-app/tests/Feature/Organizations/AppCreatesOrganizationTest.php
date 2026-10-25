<?php

use App\Models\User;
use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Integration\Actions\ManageIntegration;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

const DESKTOP_CALLBACK = 'http://127.0.0.1:48217/oauth';

beforeEach(function () {
    Notification::fake();
    config(['platform.marketing.trial_days' => 60]);

    $importer = app(CatalogImporter::class);
    $importer->import(json_decode((string) file_get_contents(database_path('catalogs/platform.json')), true));
    $importer->import(json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true));
    $this->retail = Application::query()->where('code', 'retail')->sole();
    $this->retail->forceFill(['status' => 'available', 'trial_enabled' => true])->save();

    $this->desktop = app(ManageIntegration::class)->registerSignInClient($this->retail, 'Retail desktop', false, [DESKTOP_CALLBACK], null, null);
});

/** A person's access token from the app's public client (authorization code + PKCE). */
function desktopToken(User $user, string $clientId): string
{
    $verifier = Str::random(64);
    $location = (string) test()->actingAs($user)->get(route('passport.authorizations.authorize').'?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => DESKTOP_CALLBACK,
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
        'redirect_uri' => DESKTOP_CALLBACK,
        'code' => $callback['code'],
        'code_verifier' => $verifier,
    ])->assertOk()->json('access_token');

    // The rest of the test speaks with the token alone, as the app does.
    auth()->guard('web')->logout();

    return (string) $token;
}

function desktopApiUrl(string $path): string
{
    return PlatformHosts::url(PlatformHosts::auth(), 'api/v1/account/'.ltrim($path, '/'));
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function desktopBusiness(array $overrides = []): array
{
    return array_merge([
        'display_name' => 'Gudele Pharmacy',
        'legal_name' => 'Gudele Pharmacy Ltd',
        'slug' => 'gudele',
        'country_code' => 'ss',
        'default_currency' => 'ssp',
        'timezone' => 'Africa/Juba',
        'branch_name' => 'Gudele',
        'branch_city' => 'Juba',
    ], $overrides);
}

it('creates the business with its owner, first branch and the app\'s trial', function () {
    $user = User::factory()->create();
    $token = desktopToken($user, $this->desktop['client_id']);

    $response = $this->withToken($token)->postJson(desktopApiUrl('organizations'), desktopBusiness())
        ->assertCreated()
        ->assertJsonPath('organization.slug', 'gudele')
        ->assertJsonPath('organization.name', 'Gudele Pharmacy')
        ->assertJsonPath('trial.application', 'retail')
        ->assertJsonPath('trial.ends_at', now()->addDays(60)->toDateString());

    $organization = Organization::query()->findOrFail($response->json('organization.id'));
    expect($organization->owner_membership_id)->not->toBeNull()
        ->and($organization->created_by_user_id)->toBe($user->id);

    app(TenantContext::class)->run($organization, function () {
        expect(Branch::query()->sole()->only(['name', 'code', 'city', 'country_code']))
            ->toBe(['name' => 'Gudele', 'code' => 'MAIN', 'city' => 'Juba', 'country_code' => 'SS'])
            ->and(Entitlement::query()->where('application_id', $this->retail->id)->where('source', 'trial')->exists())->toBeTrue();
    });
});

it('creates the business without a trial when the app\'s application offers none', function () {
    $this->retail->forceFill(['trial_enabled' => false])->save();
    $token = desktopToken(User::factory()->create(), $this->desktop['client_id']);

    $this->withToken($token)->postJson(desktopApiUrl('organizations'), desktopBusiness())
        ->assertCreated()
        ->assertJsonPath('trial', null);
});

it('checks the workspace address', function () {
    $token = desktopToken(User::factory()->create(), $this->desktop['client_id']);
    Organization::factory()->create()->slugs()->create(['slug' => 'taken', 'is_primary' => false]);

    $this->withToken($token)->getJson(desktopApiUrl('organizations/slug-availability?slug=gudele'))
        ->assertOk()->assertExactJson(['available' => true, 'errors' => []]);
    $this->withToken($token)->getJson(desktopApiUrl('organizations/slug-availability?slug=taken'))
        ->assertOk()->assertJsonPath('available', false);

    $this->withToken($token)->postJson(desktopApiUrl('organizations'), desktopBusiness(['slug' => 'taken']))
        ->assertUnprocessable()->assertJsonValidationErrors('slug');
});

it('needs the first branch\'s name', function () {
    $token = desktopToken(User::factory()->create(), $this->desktop['client_id']);

    $this->withToken($token)->postJson(desktopApiUrl('organizations'), desktopBusiness(['branch_name' => '']))
        ->assertUnprocessable()->assertJsonValidationErrors('branch_name');

    expect(Organization::query()->count())->toBe(0);
});

it('refuses accounts whose email is not confirmed yet', function () {
    $token = desktopToken(User::factory()->unverified()->create(), $this->desktop['client_id']);

    $this->withToken($token)->postJson(desktopApiUrl('organizations'), desktopBusiness())
        ->assertForbidden()->assertJsonPath('error', 'email_unverified');
});

it('refuses accounts that are no longer active', function () {
    $user = User::factory()->create();
    $token = desktopToken($user, $this->desktop['client_id']);
    $user->forceFill(['status' => 'suspended'])->save();

    $this->withToken($token)->postJson(desktopApiUrl('organizations'), desktopBusiness())->assertUnauthorized();
});

it('refuses tokens from a client that is not an app\'s public client', function () {
    $token = desktopToken(User::factory()->create(), $this->desktop['client_id']);
    DB::table('application_clients')->where('oauth_client_ref', $this->desktop['client_id'])->update(['status' => 'disabled']);

    $this->withToken($token)->postJson(desktopApiUrl('organizations'), desktopBusiness())
        ->assertForbidden()->assertJsonPath('error', 'unknown_client');
});

it('refuses product backends\' client-credentials tokens', function () {
    $service = app(ManageIntegration::class)->registerServiceClient($this->retail, 'Retail backend', null);
    $token = $this->post(route('passport.token'), [
        'grant_type' => 'client_credentials',
        'client_id' => $service['client_id'],
        'client_secret' => $service['client_secret'],
        'scope' => 'access.read',
    ])->json('access_token');

    $this->withToken($token)->postJson(desktopApiUrl('organizations'), desktopBusiness())
        ->assertForbidden()->assertJsonPath('error', 'user_token_required');
});

it('refuses requests without a valid token', function () {
    $this->postJson(desktopApiUrl('organizations'), desktopBusiness())->assertUnauthorized();
    $this->withToken('not-a-token')->getJson(desktopApiUrl('organizations/slug-availability?slug=gudele'))->assertUnauthorized();
});

it('lists the person\'s businesses that use the app', function () {
    $user = User::factory()->create();
    $token = desktopToken($user, $this->desktop['client_id']);

    $this->withToken($token)->postJson(desktopApiUrl('organizations'), desktopBusiness())->assertCreated();
    $this->withToken($token)->postJson(desktopApiUrl('organizations'), desktopBusiness(['slug' => 'munuki', 'display_name' => 'Munuki Pharmacy']))->assertCreated();
    // A business without the app (no trial on offer) is not listed.
    $this->retail->forceFill(['trial_enabled' => false])->save();
    $this->withToken($token)->postJson(desktopApiUrl('organizations'), desktopBusiness(['slug' => 'other', 'display_name' => 'Other']))->assertCreated();
    // Nor is someone else's.
    $this->withToken(desktopToken(User::factory()->create(), $this->desktop['client_id']))
        ->postJson(desktopApiUrl('organizations'), desktopBusiness(['slug' => 'theirs']))->assertCreated();

    $this->withToken($token)->getJson(desktopApiUrl('organizations'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.slug', 'gudele')
        ->assertJsonPath('data.0.name', 'Gudele Pharmacy')
        ->assertJsonPath('data.0.is_owner', true)
        ->assertJsonPath('data.1.slug', 'munuki');
});

it('lists nothing for someone in no business', function () {
    $this->withToken(desktopToken(User::factory()->create(), $this->desktop['client_id']))
        ->getJson(desktopApiUrl('organizations'))
        ->assertOk()
        ->assertExactJson(['data' => []]);
});
