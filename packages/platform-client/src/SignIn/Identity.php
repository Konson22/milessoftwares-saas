<?php

namespace Miles\PlatformClient\SignIn;

/**
 * The verified person behind a completed sign-in. subject is the platform
 * user id: immutable, use it as the local user's primary key.
 *
 * email is empty for an account a business added with a username instead
 * (a pump attendant, say); username is empty for everyone else.
 */
final readonly class Identity
{
    public function __construct(
        public string $subject,
        public string $name,
        public string $email,
        public bool $emailVerified,
        public string $username = '',
    ) {}

    /**
     * What the person signs in with: the email, or the username when there
     * is none. Stored in the local user's email column, which every product
     * requires: usernames never contain "@" and are unique on the platform,
     * so they cannot collide with an address.
     */
    public function signInName(): string
    {
        return $this->email !== '' ? $this->email : $this->username;
    }
}
