<?php

use App\Support\Hosts\PlatformHosts;

function csp(string $header): array
{
    $directives = [];
    foreach (explode(';', $header) as $part) {
        $tokens = preg_split('/\s+/', trim($part)) ?: [];
        $directives[array_shift($tokens)] = $tokens;
    }

    return $directives;
}

test('every page gets the hardening headers and a nonce-based script policy', function () {
    $response = $this->get(PlatformHosts::url(PlatformHosts::root(), '/'))->assertOk();

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

    $policy = csp((string) $response->headers->get('Content-Security-Policy'));
    expect($policy['frame-ancestors'])->toBe(["'none'"])
        ->and($policy['object-src'])->toBe(["'none'"])
        ->and($policy['base-uri'])->toBe(["'self'"])
        ->and($policy['script-src'])->not->toContain("'unsafe-inline'")
        ->and($policy['script-src'])->not->toContain("'unsafe-eval'");

    // The inline theme script carries the same nonce the header allows.
    $nonce = collect($policy['script-src'])->first(fn ($t) => str_starts_with($t, "'nonce-"));
    expect($nonce)->not->toBeNull();
    $value = substr($nonce, 7, -1);
    expect($response->getContent())->toContain('<script nonce="'.$value.'">');
});

test('the nonce changes on every request', function () {
    $first = $this->get(PlatformHosts::url(PlatformHosts::root(), '/'))->headers->get('Content-Security-Policy');
    $second = $this->get(PlatformHosts::url(PlatformHosts::root(), '/'))->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

test('API responses are covered too', function () {
    $this->getJson(PlatformHosts::url(PlatformHosts::auth(), 'api/v1/organizations/nope'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});

test('HSTS is sent only over HTTPS and only when enabled', function () {
    $url = PlatformHosts::url(PlatformHosts::root(), '/');

    config(['platform.security_headers.hsts' => true]);
    $this->get($url)->assertHeaderMissing('Strict-Transport-Security');
    $this->get(str_replace('http://', 'https://', $url))
        ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');

    config(['platform.security_headers.hsts' => false]);
    $this->get(str_replace('http://', 'https://', $url))->assertHeaderMissing('Strict-Transport-Security');
});

test('the policy can run in report-only mode with a report endpoint', function () {
    config([
        'platform.security_headers.csp_report_only' => true,
        'platform.security_headers.csp_report_uri' => 'https://reports.example.test/csp',
    ]);

    $response = $this->get(PlatformHosts::url(PlatformHosts::root(), '/'))
        ->assertHeaderMissing('Content-Security-Policy');

    expect(csp((string) $response->headers->get('Content-Security-Policy-Report-Only'))['report-uri'])
        ->toBe(['https://reports.example.test/csp']);
});

test('no origin gets cross-origin access to the API', function () {
    $response = $this->call('OPTIONS', PlatformHosts::url(PlatformHosts::auth(), 'api/v1/health'), server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    $response->assertHeaderMissing('Access-Control-Allow-Origin');

    $this->getJson(PlatformHosts::url(PlatformHosts::auth(), 'api/v1/organizations/nope'), ['Origin' => 'https://evil.example'])
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
