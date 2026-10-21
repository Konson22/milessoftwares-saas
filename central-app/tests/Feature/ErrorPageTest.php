<?php

use App\Models\User;
use App\Support\Hosts\PlatformHosts;

it('offers sign in and sign up, not the dashboard, to a signed-out visitor', function () {
    $this->get(PlatformHosts::url(PlatformHosts::app(), '/no-such-page'))
        ->assertNotFound()
        ->assertSee('Page not found')
        ->assertSee(PlatformHosts::url(PlatformHosts::app(), '/auth/login'), false)
        ->assertSee(PlatformHosts::url(PlatformHosts::auth(), '/register'), false)
        ->assertDontSee('Go to dashboard')
        ->assertDontSee('Workspace home');
});

it('sends a signed-in visitor back to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(PlatformHosts::url(PlatformHosts::app(), '/no-such-page'))
        ->assertNotFound()
        ->assertSee('Go to dashboard')
        ->assertDontSee('Create account');
});

it('answers unknown addresses with 404 for every method, without a CSRF token', function () {
    $this->post(PlatformHosts::url(PlatformHosts::app(), '/no-such-page'))->assertNotFound();
    $this->delete(PlatformHosts::url(PlatformHosts::root(), '/no-such-page'))->assertNotFound();
});

it('still answers 405 for an address that exists for other methods', function () {
    $this->post(PlatformHosts::url(PlatformHosts::root(), '/demo'))
        ->assertMethodNotAllowed()
        ->assertHeader('Allow', 'GET, HEAD');
});
