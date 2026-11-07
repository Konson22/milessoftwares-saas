<?php

use App\Models\User;

function host(string $label = ''): string
{
    $root = config('platform.root_domain');

    return 'http://'.($label === '' ? $root : "{$label}.{$root}");
}

it('serves the marketing site on the apex domain', function () {
    $this->get(host().'/')->assertOk()->assertInertia(fn ($page) => $page->component('public/home'));
});

it('redirects www permanently to the apex, keeping path and query', function () {
    $this->get(host('www').'/pricing?plan=retail')
        ->assertStatus(301)
        ->assertRedirect(host().'/pricing?plan=retail');
});

it('serves sign-in on the auth host only', function () {
    $this->get(host('auth').'/login')->assertOk()->assertInertia(fn ($page) => $page->component('central/auth/login'));

    $this->get(host().'/login')->assertNotFound();
});

it('serves the list of businesses on the auth host only', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(host('auth').'/workspaces')->assertOk();
    $this->actingAs($user)->get(host().'/workspaces')->assertNotFound();
});

it('sends everything on the retired app host elsewhere, permanently', function () {
    $this->get(host('app').'/login')->assertStatus(301);
    $this->get(host('app').'/dashboard')->assertStatus(301)->assertRedirect(host('auth').'/workspaces/continue');
});

it('rejects reserved first-level hosts', function (string $label) {
    $this->get(host($label).'/')->assertNotFound();
})->with(['api', 'mail', 'support', 'status', 'cdn', 'billing', 'miles']);

it('rejects multi-level subdomains', function () {
    $this->get('http://a.b.'.config('platform.root_domain').'/')->assertNotFound();
    $this->get('http://www.auth.'.config('platform.root_domain').'/login')->assertNotFound();
});

it('rejects hosts outside the platform domain', function () {
    $this->get('http://evil.example.com/')->assertNotFound();
    $this->get('http://'.config('platform.root_domain').'.evil.example.com/')->assertNotFound();
});

it('does not expose platform-host paths on the tenant wildcard', function () {
    // auth.<root>/unknown must not fall through to tenant resolution as "auth".
    $this->get(host('auth').'/not-a-route')->assertNotFound();
});
