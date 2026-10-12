<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Http\Middleware\ResolveTenant;
use Miles\PlatformClient\Tests\Fixtures\FakePlatform;
use Miles\PlatformClient\Tests\TestCase;

beforeEach(function () {
    $this->platform = (new FakePlatform)->install();
    $this->ada = FakePlatform::subject('ada');
    $this->platform->grant('acme', $this->ada, [FakePlatform::role('pharmacist', ['pharmacy.dispense'])]);
});

function accessCalls(): int
{
    return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/effective-access'))->count();
}

describe('tenant from the host', function () {
    it('finds the slug only on first-level subdomains of the root domain', function (string $host, ?string $slug) {
        expect(ResolveTenant::slugFor($host, TestCase::ROOT))->toBe($slug);
    })->with([
        ['acme.milessoftwares.test', 'acme'],
        ['ACME.milessoftwares.test.', 'acme'],
        ['milessoftwares.test', null],
        ['a.b.milessoftwares.test', null],
        ['acme.milessoftwares.test.evil.com', null],
        ['acme.evilmilessoftwares.test', null],
        ['-bad.milessoftwares.test', null],
    ]);

    it('answers 404 for hosts that are not a workspace', function (string $url) {
        $this->get($url)->assertNotFound();
    })->with([
        'apex' => ['http://'.TestCase::ROOT.'/pharmacy/auth/login'],
        'other domain' => ['http://acme.example.com/pharmacy/auth/login'],
        'deeper subdomain' => ['http://x.acme.'.TestCase::ROOT.'/pharmacy/auth/login'],
    ]);

    it('answers 404 for an organization this application cannot see', function () {
        $this->get($this->url('nobody', 'auth/login'))->assertNotFound();
    });

    it('answers 403 for a suspended organization', function () {
        $this->platform->organizations['acme']['status'] = 'suspended';

        $this->get($this->url('acme', 'auth/login'))->assertForbidden();
    });

    it('answers 503 when the platform is down', function () {
        $this->platform->down = true;

        $this->get($this->url('acme', 'auth/login'))->assertStatus(503);
    });
});

describe('access to product pages', function () {
    it('sends guests to sign in', function () {
        $this->get($this->url('acme'))->assertRedirect($this->url('acme', 'auth/login'));
    });

    it('lets a member with access in', function () {
        signIn($this, $this->platform);

        $this->get($this->url('acme'))->assertOk();
    });

    it('does not accept a session from another workspace', function () {
        $this->platform->grant('globex', $this->ada, [FakePlatform::role('pharmacist', ['pharmacy.dispense'])]);
        signIn($this, $this->platform, 'acme');

        $this->get($this->url('globex'))->assertRedirect($this->url('globex', 'auth/login'));
        expect(Auth::check())->toBeFalse();
    });

    it('reuses the access answer within its max age', function () {
        signIn($this, $this->platform);
        $before = accessCalls();

        $this->get($this->url('acme'))->assertOk();
        $this->get($this->url('acme'))->assertOk();

        expect(accessCalls())->toBe($before);
    });

    it('checks again after the cache window and applies a revocation', function () {
        signIn($this, $this->platform);
        $this->platform->grant('acme', $this->ada, allowed: false);

        $this->get($this->url('acme'))->assertOk(); // still cached

        $this->travel(61)->seconds();
        $this->get($this->url('acme'))->assertForbidden();
    });

    it('applies a revocation at once when the organization cache is dropped', function () {
        signIn($this, $this->platform);
        $this->platform->grant('acme', $this->ada, allowed: false);

        app(PlatformApi::class)->forget(FakePlatform::uuid('acme'));

        $this->get($this->url('acme'))->assertForbidden();
    });

    it('answers 503, not 500, when the platform is down mid-session', function () {
        signIn($this, $this->platform);
        app(PlatformApi::class)->forget(FakePlatform::uuid('acme'));
        $this->platform->down = true;

        $this->get($this->url('acme'))->assertStatus(503);
    });
});

describe('catalog permissions through the Gate', function () {
    it('allows a permission the member holds', function () {
        signIn($this, $this->platform);

        $this->get($this->url('acme', 'dispense'))->assertSee('can dispense');
    });

    it('denies a permission no role grants', function () {
        $this->platform->grant('acme', $this->ada, [FakePlatform::role('cashier', ['pharmacy.sales.create'])]);
        signIn($this, $this->platform);

        $this->get($this->url('acme', 'dispense'))->assertSee('cannot dispense');
    });

    it('gives the organization owner every permission', function () {
        $this->platform->grant('acme', $this->ada, owner: true);
        signIn($this, $this->platform);

        $this->get($this->url('acme', 'dispense'))->assertSee('can dispense');
    });
});
