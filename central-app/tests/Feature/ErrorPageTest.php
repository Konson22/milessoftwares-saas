<?php

use App\Models\User;
use App\Support\Hosts\PlatformHosts;

it('offers sign in and sign up, not the businesses list, to a signed-out visitor', function () {
    $this->get(PlatformHosts::url(PlatformHosts::auth(), '/no-such-page'))
        ->assertNotFound()
        ->assertSee('Page not found')
        ->assertSee(PlatformHosts::url(PlatformHosts::auth(), '/login'), false)
        ->assertSee(PlatformHosts::url(PlatformHosts::auth(), '/register'), false)
        ->assertDontSee('Your businesses')
        ->assertDontSee('Workspace home');
});

it('sends a signed-in visitor back to their businesses', function () {
    $this->actingAs(User::factory()->create())
        ->get(PlatformHosts::url(PlatformHosts::auth(), '/no-such-page'))
        ->assertNotFound()
        ->assertSee('Your businesses')
        ->assertSee(PlatformHosts::url(PlatformHosts::auth(), '/workspaces'), false)
        ->assertDontSee('Create account');
});

it('answers unknown addresses with 404 for every method, without a CSRF token', function () {
    $this->post(PlatformHosts::url(PlatformHosts::auth(), '/no-such-page'))->assertNotFound();
    $this->delete(PlatformHosts::url(PlatformHosts::root(), '/no-such-page'))->assertNotFound();
});

it('still answers 405 for an address that exists for other methods', function () {
    $this->post(PlatformHosts::url(PlatformHosts::root(), '/demo'))
        ->assertMethodNotAllowed()
        ->assertHeader('Allow', 'GET, HEAD');
});
