<?php

namespace Miles\PlatformClient\Api;

use RuntimeException;

/**
 * The platform understood the request and said no (HTTP 422, or 409): the
 * answer's validation errors, by field, to show next to the form.
 */
final class PlatformRefused extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
