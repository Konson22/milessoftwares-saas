<?php

/**
 * Central side of the E2E run, on e2e_central:
 *
 *   php central.php setup             catalogs, clients, two organizations
 *   php central.php entitle <slug> <app>
 *   php central.php revoke <slug> <app>
 *   php central.php sign-expired <slug> <audience> <source>
 *                                     a token signed with Central's real key,
 *                                     already expired (forged-token scenario)
 *
 * Entitling provisions the organization in the product through the
 * product's own /platform/tenants endpoint, as staff granting it would.
 */

require __DIR__.'/lib.php';

$laravel = e2e_boot('central');

use App\Models\User;
use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Integration\Actions\ManageIntegration;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Modules\Identity\OpenId\SigningKey;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

$action = $argv[1] ?? '';

function organizationBySlug(string $slug): App\Modules\Organizations\Models\Organization
{
    return OrganizationSlug::query()->where('slug', $slug)->where('is_primary', true)->firstOrFail()->organization;
}

if ($action === 'setup') {
    $integration = app(ManageIntegration::class);
    $clients = [];
    foreach (['finance' => 'finance-app', 'projects' => 'project-management'] as $code => $folder) {
        $catalog = json_decode((string) file_get_contents(e2e_root()."/{$folder}/platform/catalog.json"), true, flags: JSON_THROW_ON_ERROR);
        app(CatalogImporter::class)->import($catalog);
        $application = Application::query()->where('code', $code)->sole();
        $application->forceFill([
            'route_prefix' => $code,
            'provisioning_method' => 'sync_api',
            'integration_endpoint' => 'http://127.0.0.1:'.E2E_PORTS[$code]."/{$code}/platform",
        ])->save();
        $signIn = $integration->registerSignInClient($application, "{$application->name} web", true, [], "/{$code}/auth/callback", null);
        $service = $integration->registerServiceClient($application, "{$application->name} backend", null);
        $webhook = $integration->configureWebhook($application, 'http://127.0.0.1:'.E2E_PORTS[$code]."/{$code}/platform/webhooks", true, null);
        $clients[$code] = [
            'sign_in_id' => $signIn['client_id'], 'sign_in_secret' => (string) $signIn['client_secret'],
            'service_id' => $service['client_id'], 'service_secret' => (string) $service['client_secret'],
            'webhook_secret' => (string) $webhook,
        ];
    }

    $owner = User::query()->forceCreate([
        'id' => (string) Str::uuid7(),
        'name' => 'E2E Owner',
        'email' => 'owner-'.Str::random(6).'@e2e.test',
        'password' => bcrypt(Str::random(32)),
        'email_verified_at' => now(),
    ]);
    $organizations = [];
    foreach (['acme' => 'Acme Relief', 'globex' => 'Globex Aid'] as $slug => $name) {
        $organization = app(CreateOrganization::class)->handle($owner, [
            'display_name' => $name, 'slug' => $slug, 'country_code' => 'SS',
            'default_currency' => 'SSP', 'timezone' => 'Africa/Juba', 'organization_type' => null,
        ]);
        $organizations[$slug] = (string) $organization->getKey();
    }

    e2e_save(['clients' => $clients, 'organizations' => $organizations]);
    e2e_out(['clients' => array_keys($clients), 'organizations' => $organizations]);
}

if ($action === 'entitle' || $action === 'revoke') {
    [$slug, $code] = [$argv[2], $argv[3]];
    $organization = organizationBySlug($slug);
    $application = Application::query()->where('code', $code)->sole();
    $entitlements = app(ManageEntitlements::class);
    if ($action === 'entitle') {
        $entitlements->grantManual($organization, $application, null, 'E2E run');
        e2e_out(['entitled' => "{$slug}:{$code}"]);
    }
    $current = app(TenantContext::class)->run($organization, fn () => Entitlement::query()->current()
        ->where('application_id', $application->id)->whereNull('feature_key')->firstOrFail());
    $entitlements->revoke($organization, $current, null, 'E2E run: entitlement revoked');
    e2e_out(['revoked' => "{$slug}:{$code}"]);
}

if ($action === 'sign-expired') {
    [$slug, $audience, $source] = [$argv[2], $argv[3], $argv[4]];
    // Signed with the platform's real signing key and kid, but expired.
    $private = (string) file_get_contents(Laravel\Passport\Passport::keyPath('oauth-private.key'));
    $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($private), InMemory::plainText('-'));
    $kid = SigningKey::kid();
    $past = new DateTimeImmutable('-20 minutes');
    $token = $config->builder()
        ->withHeader('kid', (string) $kid)
        ->issuedBy(PlatformHosts::url(PlatformHosts::auth()))
        ->permittedFor($audience)
        ->relatedTo($source)
        ->identifiedBy((string) Str::uuid())
        ->issuedAt($past)
        ->canOnlyBeUsedAfter($past)
        ->expiresAt($past->modify('+5 minutes'))
        ->withClaim('org', organizationBySlug($slug)->getKey())
        ->withClaim('scope', 'events.write')
        ->getToken($config->signer(), $config->signingKey())
        ->toString();
    e2e_out(['token' => $token]);
}

fwrite(STDERR, "Unknown action {$action}\n");
exit(1);
