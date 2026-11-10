<?php

/*
| Passwords are hashed with Argon2id. Hashes made with other algorithms
| (none exist today) would still verify, and Laravel rehashes them on the
| next successful sign-in.
*/

return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    // OWASP minimums for Argon2id: 19 MiB memory, 2 iterations. These are
    // above them; tests lower them (phpunit.xml) to stay fast.
    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', true),
    ],

    'rehash_on_login' => true,

];
