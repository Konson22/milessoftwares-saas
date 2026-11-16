<?php

use App\Mel\Models\Programme;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;

it('sends guests to sign in through the platform', function () {
    $this->platform()->organization('acme');

    $this->get($this->workspaceUrl('acme'))->assertRedirect($this->workspaceUrl('acme', 'auth/login'));
});

it('shows M&E to a member with access, inside their workspace', function () {
    $this->actingAsMember('acme', ['mel.programmes.view']);

    $this->get($this->workspaceUrl('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('mel/home')
            ->where('product.code', 'mel')
            ->where('workspace.slug', 'acme')
            ->where('workspace.url', 'http://acme.milesoftwares.test/')
            ->where('access.permissions', ['mel.programmes.view']));
});

it('refuses a platform user without access to M&E', function () {
    $user = $this->platformUser();
    $org = $this->platform()->organization('acme');
    $this->platform()->grant('acme', $user->getAuthIdentifier(), allowed: false);

    $this->actingAs($user)
        ->withSession([RequirePlatformAccess::SESSION_TENANT => $org['id']])
        ->get($this->workspaceUrl('acme'))
        ->assertForbidden();
});

it('works for an organization with M&E alone, and keeps its data while access is revoked', function () {
    // Nothing of Projects exists here: M&E is its own application and database.
    $user = actingAsRole('mel_manager');
    ['programme' => $programme] = setUpProgramme();

    $this->get(mel("programmes/{$programme->id}"))->assertOk();

    // The platform stops allowing M&E (subscription ended or access revoked).
    $org = $this->platform()->organization('acme');
    $this->platform()->grant('acme', $user->getAuthIdentifier(), allowed: false);
    app(PlatformApi::class)->forget($org['id']); // as Central's access-changed event does
    $this->withSession([RequirePlatformAccess::SESSION_TENANT => $org['id']])
        ->get(mel("programmes/{$programme->id}"))
        ->assertForbidden();

    expect(inAcme(fn () => Programme::query()->whereKey($programme->id)->exists()))->toBeTrue();

    // Allowed again: everything is still there.
    $this->actingAsMember('acme', rolePermissions('mel_manager'), user: $user);
    app(PlatformApi::class)->forget($org['id']);
    $this->get(mel("programmes/{$programme->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('mel/programmes/show')->where('programme.code', 'WASH'));
});

it('does not serve hosts that are not a workspace', function () {
    $this->get('http://milesoftwares.test/')->assertNotFound();
    $this->get('http://evil.example/')->assertNotFound();
});

it('sends the platform browser policy', function () {
    $this->actingAsMember('acme', ['mel.programmes.view']);

    $this->get($this->workspaceUrl('acme'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy');
});
