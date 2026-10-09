<?php

use App\Modules\Identity\Support\DeviceLabel;

it('labels common browsers and systems', function (?string $userAgent, string $label) {
    expect(DeviceLabel::from($userAgent))->toBe($label);
})->with([
    'edge on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0', 'Edge on Windows'],
    'chrome on android' => ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36', 'Chrome on Android'],
    'safari on ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', 'Safari on iOS'],
    'firefox on linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0', 'Firefox on Linux'],
    'unknown' => ['curl/8.0', 'Unknown browser'],
    'missing' => [null, 'Unknown device'],
]);
