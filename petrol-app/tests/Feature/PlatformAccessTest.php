<?php

use Inertia\Testing\AssertableInertia as Assert;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;

it('sends guests to sign in through the platform', function () {
    $this->platform()->organization('acme');

    $this->get($this->workspaceUrl('acme'))->assertRedirect($this->workspaceUrl('acme', 'auth/login'));
});

it('shows the product to a member with access, inside their workspace', function () {
    $branch = $this->platform()->branch('acme', 'Juba');
    $this->actingAsMember('acme', ['petrol.sales.view'], branch: $branch);

    $this->get($this->workspaceUrl('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('product.code', 'petrol')
            ->where('workspace.slug', 'acme')
            ->where('workspace.url', 'http://acme.milesoftwares.test/')
            ->where('access.permissions', ['petrol.sales.view']));
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
    $branch = $this->platform()->branch('acme', 'Juba');
    $this->actingAsMember('acme', ['petrol.sales.view'], branch: $branch);

    $this->get($this->workspaceUrl('acme'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy');
});

it('sends a member whose only role is Attendant to the mobile app', function () {
    $branch = $this->platform()->branch('acme', 'Juba');
    $user = $this->actingAsMember('acme', branch: $branch);
    $scope = ['scope_type' => 'branch', 'branch_id' => $branch];
    $this->platform()->grant('acme', $user->getAuthIdentifier(), [['role' => 'attendant', 'permissions' => ['petrol.sales.view', 'petrol.sales.create'], 'scope' => $scope]]);

    foreach (['', 'sales', 'branch'] as $path) {
        $this->get($this->workspaceUrl('acme', $path))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('mobile-only'));
    }
});

it('opens the web to an attendant who holds another role too, and to owners', function () {
    $branch = $this->platform()->branch('acme', 'Juba');
    $user = $this->actingAsMember('acme', branch: $branch);
    $scope = ['scope_type' => 'branch', 'branch_id' => $branch];
    $this->platform()->grant('acme', $user->getAuthIdentifier(), [
        ['role' => 'attendant', 'permissions' => ['petrol.sales.view'], 'scope' => $scope],
        ['role' => 'accountant', 'permissions' => ['petrol.reports.view'], 'scope' => $scope],
    ]);
    $this->get($this->workspaceUrl('acme'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('home'));

    $this->platform()->grant('acme', $user->getAuthIdentifier(), [['role' => 'attendant', 'permissions' => ['petrol.sales.view'], 'scope' => $scope]], owner: true);
    $this->get($this->workspaceUrl('acme'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('home'));
});

it('serves an attendant the pages in the mobile app', function () {
    $branch = $this->platform()->branch('acme', 'Juba');
    $user = $this->actingAsAppMember('acme', branch: $branch);
    $this->platform()->grant('acme', $user->getAuthIdentifier(), [['role' => 'attendant', 'permissions' => ['petrol.sales.view'], 'scope' => ['scope_type' => 'branch', 'branch_id' => $branch]]]);

    $this->withHeader('X-Branch-Id', $branch)
        ->getJson($this->workspaceUrl('acme', 'api/v1/app/sales'))
        ->assertOk()
        ->assertJsonPath('component', 'sales/index');
});
