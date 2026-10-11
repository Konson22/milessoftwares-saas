<?php

namespace App\Support\Inertia;

use InvalidArgumentException;

/**
 * Inertia page names are "{site}/{page}": "public/pricing" is
 * resources/js/sites/public/pages/pricing.tsx. The frontend side of this
 * rule is resources/js/sites/index.ts.
 */
final class SitePages
{
    public const SITES = ['public', 'admin', 'central'];

    /**
     * Source path of a page, relative to the application root.
     */
    public static function path(string $component): string
    {
        $slash = strpos($component, '/');
        $site = $slash === false ? '' : substr($component, 0, $slash);

        if ($slash === false || ! in_array($site, self::SITES, true)) {
            throw new InvalidArgumentException("Page [{$component}] must start with a site: ".implode(', ', self::SITES).'.');
        }

        return 'resources/js/sites/'.$site.'/pages/'.substr($component, $slash + 1).'.tsx';
    }
}
