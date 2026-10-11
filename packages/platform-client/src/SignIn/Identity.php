<?php

namespace Miles\PlatformClient\SignIn;

/**
 * The verified person behind a completed sign-in. subject is the platform
 * user id: immutable, use it as the local user's primary key.
 */
final readonly class Identity
{
    public function __construct(
        public string $subject,
        public string $name,
        public string $email,
        public bool $emailVerified,
    ) {}
}
