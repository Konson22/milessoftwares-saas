<?php

use Miles\PlatformClient\SignIn\Identity;

it('signs in by email, or by username for an account without one', function () {
    $withEmail = new Identity('01a1d679-0c01-70a9-ab69-0bf34af43f85', 'Deng', 'deng@example.test', true);
    $withUsername = new Identity('01a1d679-0c01-70a9-ab69-0bf34af43f86', 'Achol', '', true, 'achol');

    expect($withEmail->signInName())->toBe('deng@example.test')
        ->and($withUsername->signInName())->toBe('achol');
});
