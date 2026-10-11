<?php

namespace App\Support\Inertia;

use Illuminate\View\FileViewFinder;
use InvalidArgumentException;

/**
 * Lets Inertia check that a rendered page exists (inertia.pages and the
 * testing assertions) using the "{site}/{page}" layout of SitePages.
 */
final class SitePageFinder extends FileViewFinder
{
    public function find($name): string
    {
        $path = base_path(SitePages::path($name));

        if (! $this->files->exists($path)) {
            throw new InvalidArgumentException("Page [{$name}] not found at ".SitePages::path($name).'.');
        }

        return $path;
    }
}
