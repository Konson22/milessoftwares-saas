<?php

use Inertia\Testing\AssertableInertia as Assert;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;

it('sends guests to sign in through the platform', function () {
    $this->platform()->organization('acme');

    $this->get($this->workspaceUrl('acme'))->assertRedirect($this->workspaceUrl('acme', 'auth/login'));
});

it('shows the product to a member with access, inside their workspace', function () {
    $this->actingAsMember('acme', ['finance.accounts.view']);

    $this->get($this->workspaceUrl('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('product.code', 'finance')
            ->where('workspace.slug', 'acme')
            ->where('workspace.url', 'http://acme.milesoftwares.test/')
            ->where('access.permissions', ['finance.accounts.view']));
});

it('refuses a platform user without access to the product', function () {
    $user = $this->platformUser();
    $org = $this->platform()->organization('acme');
    $this->platform()->grant('acme', $user->getAuthIdentifier(), allowed: false);

    $this->actingAs($user)
        ->withSession([RequirePlatformAccess::SESSION_TENANT => $org['id']])
        ->get($this->workspaceUrl('acme'))
        ->assertForbidden();
});

it('does not serve hosts that are not a workspace', function () {
    $this->get('http://milesoftwares.test/')->assertNotFound();
    $this->get('http://evil.example/')->assertNotFound();
});

it('sends the platform browser policy', function () {
    $this->actingAsMember('acme', ['finance.accounts.view']);

    $this->get($this->workspaceUrl('acme'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy');
});
