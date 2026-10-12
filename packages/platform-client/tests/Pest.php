<?php

use Miles\PlatformClient\Tests\Fixtures\FakePlatform;
use Miles\PlatformClient\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Runs the browser sign-in against the fake platform: /{path}/auth/login,
 * then the callback with the state the product generated. Returns the
 * callback response.
 */
function signIn(TestCase $test, FakePlatform $platform, string $slug = 'acme', ?callable $tamper = null)
{
    $login = $test->get($test->url($slug, 'auth/login'));
    parse_str((string) parse_url((string) $login->headers->get('Location'), PHP_URL_QUERY), $query);

    $platform->idTokenClaims['nonce'] ??= $query['nonce'] ?? '';
    $callback = ['code' => 'code-'.bin2hex(random_bytes(4)), 'state' => $query['state'] ?? ''];

    if ($tamper !== null) {
        $callback = $tamper($callback, $query);
    }

    return $test->get($test->url($slug, 'auth/callback?'.http_build_query($callback)));
}
