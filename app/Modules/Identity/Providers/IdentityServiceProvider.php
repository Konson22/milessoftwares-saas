<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Console\SyncFirstPartyClients;
use App\Modules\Identity\Http\Responses\LogoutResponse;
use App\Modules\Identity\Listeners\AuthenticationEventSubscriber;
use App\Modules\Identity\Models\OAuthClient;
use App\Modules\Identity\OpenId\SigningKey;
use App\Support\Hosts\PlatformHosts;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // OAuth endpoints are mounted on the auth host only (routes/oauth.php).
        Passport::ignoreRoutes();

        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
    }

    public function boot(): void
    {
        Event::subscribe(AuthenticationEventSubscriber::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncFirstPartyClients::class]);
        }

        $this->configureOpenIdProvider();
    }

    private function configureOpenIdProvider(): void
    {
        Passport::useClientModel(OAuthClient::class);
        Passport::tokensCan([
            ...(array) config('openid.passport.tokens_can'),
            // Service-to-service scopes for product backends (client credentials).
            'access.read' => 'Read effective access for the calling application',
            'provisioning.write' => 'Report provisioning progress',
        ]);

        // Access tokens are short-lived and held server-side by each product (BFF).
        Passport::tokensExpireIn(CarbonInterval::minutes(10));
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));

        config([
            'openid.issuedBy' => PlatformHosts::url(PlatformHosts::auth()),
            'openid.token_headers' => array_filter(['kid' => SigningKey::kid()]),
        ]);

        // Consent screen, shown to third-party clients only (see OAuthClient).
        Passport::authorizationView($this->renderConsentScreen(...));
    }

    /**
     * @param  array<string, mixed>  $parameters  Passed by Passport: client, scopes, request, authToken.
     */
    private function renderConsentScreen(array $parameters): Response
    {
        [$client, $request] = [$parameters['client'] ?? null, $parameters['request'] ?? null];

        if (! $client instanceof Client || ! $request instanceof Request || ! is_array($parameters['scopes'] ?? null)) {
            throw new LogicException('Unexpected authorization view parameters from Passport.');
        }

        return Inertia::render('auth/authorize', [
            'client' => ['id' => $client->getKey(), 'name' => $client->name],
            'scopes' => array_values(array_map(
                fn (Scope $scope) => ['id' => $scope->id, 'description' => $scope->description],
                array_filter($parameters['scopes'], fn (mixed $scope) => $scope instanceof Scope),
            )),
            'authToken' => $parameters['authToken'] ?? null,
            // Approve/deny redirect to the client, so they are plain form posts.
            'csrfToken' => csrf_token(),
            'state' => $request->query('state'),
            'nonce' => $request->query('nonce'),
        ])->toResponse($request);
    }
}
