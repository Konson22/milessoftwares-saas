<?php

namespace App\Tenancy;

use App\Models\Organization;
use Miles\PlatformClient\PlatformConfig;

/**
 * Addresses. Projects lives in each organization's workspace on the
 * platform: {slug}.<root domain>/projects (miles/platform-client resolves the
 * organization from the host). The root domain itself is the Miles Softwares
 * website. Scheme and port follow APP_URL, so the same works on
 * http://acme.milesoftwares.localhost/projects in development.
 */
final class Hosts
{
    public static function rootDomain(): string
    {
        return app(PlatformConfig::class)->rootDomain();
    }

    /**
     * The product's path on workspace hosts: /projects.
     */
    public static function path(): string
    {
        return '/'.app(PlatformConfig::class)->path();
    }

    /**
     * An absolute URL on the website.
     */
    public static function main(string $path = '/'): string
    {
        return self::url(self::rootDomain(), $path);
    }

    /**
     * An absolute URL in an organization's workspace. $path is a full path
     * (route(..., false) gives one); without it, Projects' home there. For
     * emails and links between organizations.
     */
    public static function organization(Organization|string $organization, ?string $path = null): string
    {
        $slug = $organization instanceof Organization ? $organization->slug : $organization;

        return self::url("{$slug}.".self::rootDomain(), $path ?? self::path());
    }

    private static function url(string $host, string $path): string
    {
        $root = parse_url((string) config('app.url'));
        $scheme = $root['scheme'] ?? 'https';
        $port = isset($root['port']) ? ':'.$root['port'] : '';
        $path = '/'.ltrim($path, '/');

        return rtrim("{$scheme}://{$host}{$port}{$path}", '/');
    }
}
