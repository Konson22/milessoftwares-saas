<?php

use App\Modules\Organizations\Support\SlugRules;

it('accepts well-formed slugs', function (string $slug) {
    expect(SlugRules::isWellFormed($slug))->toBeTrue()
        ->and(SlugRules::errors($slug))->toBe([]);
})->with(['manggala', 'abcpharmacy', 'jubaschool', 'juba-school', 'shop24']);

it('rejects malformed slugs', function (string $slug) {
    expect(SlugRules::isWellFormed($slug))->toBeFalse();
})->with([
    'too short' => 'abc',
    'too long' => str_repeat('a', 33),
    'leading digit' => '1shop',
    'leading hyphen' => '-shop',
    'trailing hyphen' => 'shop-',
    'double hyphen' => 'my--shop',
    'uppercase' => 'MyShop',
    'underscore' => 'my_shop',
    'dot' => 'my.shop',
    'unicode' => 'jüba',
]);

it('rejects reserved and platform labels', function (string $slug) {
    expect(SlugRules::errors($slug))->toContain('This slug is reserved.');
})->with(['auth', 'admin', 'www', 'api', 'mail', 'support', 'billing']);
