<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Miles\PlatformClient\Http\Middleware\SecurityHeaders;

beforeEach(function () {
    Route::get('/headers', fn () => '<script nonce="'.Vite::cspNonce().'"></script>')->middleware(SecurityHeaders::class);
});

it('sends the platform browser policy with a per-request nonce', function () {
    $response = $this->get('https://acme.milesoftwares.test/headers');

    $policy = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $policy, $m);

    expect($policy)
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->not->toContain('unsafe-eval')
        ->and($response->getContent())->toContain('nonce="'.$m[1].'"');

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Strict-Transport-Security');

    $other = $this->get('https://acme.milesoftwares.test/headers')->headers->get('Content-Security-Policy');
    expect($other)->not->toBe($policy);
});

it('adds HSTS on HTTPS when enabled', function () {
    config(['platform-client.security_headers.hsts' => true]);

    $this->get('https://acme.milesoftwares.test/headers')->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
    $this->get('http://acme.milesoftwares.test/headers')->assertHeaderMissing('Strict-Transport-Security');
});
