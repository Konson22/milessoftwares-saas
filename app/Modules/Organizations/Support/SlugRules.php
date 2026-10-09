<?php

namespace App\Modules\Organizations\Support;

use App\Support\Hosts\PlatformHosts;

/**
 * Business slug rules. A slug becomes the first DNS label of the workspace
 * host, so it must be a valid lowercase hostname label.
 */
final class SlugRules
{
    public static function normalize(string $slug): string
    {
        return strtolower(trim($slug));
    }

    /**
     * Syntax only: no reservation or uniqueness checks.
     */
    public static function isWellFormed(string $slug): bool
    {
        $min = (int) config('platform.slug.min_length', 4);
        $max = (int) config('platform.slug.max_length', 32);
        $length = strlen($slug);

        return $length >= $min
            && $length <= $max
            && preg_match('/^[a-z][a-z0-9-]*[a-z0-9]$/', $slug) === 1
            && ! str_contains($slug, '--');
    }

    /**
     * Validation errors for a proposed slug, excluding uniqueness (which needs
     * the database and is enforced by the unique index as well).
     *
     * @return list<string>
     */
    public static function errors(string $slug): array
    {
        $errors = [];

        if ($slug !== self::normalize($slug)) {
            $errors[] = 'The slug must be lowercase without surrounding spaces.';
        }

        if (! self::isWellFormed(self::normalize($slug))) {
            $errors[] = sprintf(
                'The slug must be %d–%d characters, start with a letter, end with a letter or digit, and contain only lowercase letters, digits and single hyphens.',
                (int) config('platform.slug.min_length', 4),
                (int) config('platform.slug.max_length', 32),
            );
        }

        if (PlatformHosts::isReserved(self::normalize($slug))) {
            $errors[] = 'This slug is reserved.';
        } elseif (self::containsBlockedTerm(self::normalize($slug))) {
            $errors[] = 'This slug is not allowed.';
        }

        return $errors;
    }

    public static function containsBlockedTerm(string $slug): bool
    {
        foreach ((array) config('platform.slug.blocked_terms', []) as $term) {
            if (is_string($term) && $term !== '' && str_contains($slug, $term)) {
                return true;
            }
        }

        return false;
    }
}
