<?php

namespace Miles\PlatformClient\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Miles\PlatformClient\PlatformClientServiceProvider;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public const ROOT = 'milessoftwares.test';

    public const ISSUER = 'https://auth.milessoftwares.test';

    public const BACKCHANNEL = 'http://10.0.0.5:8101';

    public const WEBHOOK_SECRET = 'whsec_test_secret';

    public const RETAIL_WEBHOOK_SECRET = 'whsec_retail_secret';

    protected function getPackageProviders($app): array
    {
        return [PlatformClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');

        $app['config']->set('platform-client', array_replace($app['config']->get('platform-client'), [
            'application' => 'pharmacy',
            'root_domain' => self::ROOT,
            'issuer' => self::ISSUER,
            'backchannel_url' => self::BACKCHANNEL,
            'sign_in' => ['client_id' => 'sign-in-client', 'client_secret' => 'sign-in-secret'],
            'service' => ['client_id' => 'service-client', 'client_secret' => 'service-secret'],
            'webhook_secret' => self::WEBHOOK_SECRET,
            'user_model' => User::class,
            // A second application served by the same deployment.
            'hosted' => ['retail' => [
                'path' => 'retail',
                'sign_in' => ['client_id' => 'retail-sign-in-client', 'client_secret' => 'retail-sign-in-secret'],
                'service' => ['client_id' => 'retail-service-client', 'client_secret' => 'retail-service-secret'],
                'webhook_secret' => self::RETAIL_WEBHOOK_SECRET,
            ]],
        ]));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    /**
     * Product pages under /{path}, behind the "platform" middleware group.
     *
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->prefix(app(PlatformConfig::class)->path())
            ->middleware(['web', 'platform'])
            ->group(function (Router $router) {
                $router->get('/', fn () => 'pharmacy home')->name('home');
                $router->get('/dispense', fn () => Gate::allows('pharmacy.dispense') ? 'can dispense' : 'cannot dispense');
            });

        // The hosted application's pages.
        $router->prefix('retail')
            ->middleware(['web', 'platform.app:retail', 'platform'])
            ->group(function (Router $router) {
                $router->get('/', fn () => 'retail home');
                $router->get('/sell', fn () => Gate::allows('retail.sales.create') ? 'can sell' : 'cannot sell');
            });
    }

    /**
     * A URL of this product in a workspace: url('acme', 'dispense') is
     * http://acme.<root>/pharmacy/dispense.
     */
    public function url(string $slug, string $path = ''): string
    {
        $prefix = app(PlatformConfig::class)->path();

        return rtrim('http://'.$slug.'.'.self::ROOT.'/'.$prefix.'/'.ltrim($path, '/'), '/');
    }
}
