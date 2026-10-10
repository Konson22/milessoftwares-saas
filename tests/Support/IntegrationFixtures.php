<?php

namespace Tests\Support;

use App\Modules\Applications\Models\Application;
use App\Modules\Integration\Actions\ManageIntegration;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;

/**
 * On top of AuthorizationFixtures: the retail application's service client
 * and a real client-credentials token from /oauth/token.
 */
trait IntegrationFixtures
{
    use AdminFixtures, AuthorizationFixtures;

    public Application $retail;

    /** @var array{client_id: string, client_secret: string|null} */
    public array $serviceClient;

    protected function prepareIntegrationFixtures(): void
    {
        $this->prepareAuthorizationFixtures();
        app(TenantContext::class)->clear();

        $this->retail = Application::query()->where('code', 'retail')->sole();
        $this->serviceClient = app(ManageIntegration::class)->registerServiceClient($this->retail, 'Retail backend', null);
    }

    /**
     * @param  array{client_id: string, client_secret: string|null}|null  $client
     */
    protected function serviceToken(string $scope = 'access.read', ?array $client = null): string
    {
        $client ??= $this->serviceClient;

        $response = $this->post(route('passport.token'), [
            'grant_type' => 'client_credentials',
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'scope' => $scope,
        ])->assertOk();

        return (string) $response->json('access_token');
    }

    protected function apiUrl(string $path): string
    {
        return PlatformHosts::url(PlatformHosts::auth(), 'api/v1/'.ltrim($path, '/'));
    }
}
