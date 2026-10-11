<?php

namespace App\Support\Hosts;

use App\Modules\Organizations\Support\SlugRules;

/**
 * Knows every hostname the platform answers on and how to build URLs for them.
 *
 * Classification here is purely syntactic. Whether a workspace label belongs
 * to a real organization is decided by the ResolveTenant middleware.
 */
final class PlatformHosts
{
    public static function root(): string
    {
        return strtolower((string) config('platform.root_domain'));
    }

    /**
     * Fully qualified host for a platform label, or the apex for null.
     */
    public static function host(?string $label = null): string
    {
        return $label === null || $label === '' ? self::root() : $label.'.'.self::root();
    }

    public static function auth(): string
    {
        return self::host(config('platform.hosts.auth'));
    }

    public static function app(): string
    {
        return self::host(config('platform.hosts.app'));
    }

    public static function admin(): string
    {
        return self::host(config('platform.hosts.admin'));
    }

    public static function www(): string
    {
        return self::host(config('platform.hosts.www'));
    }

    public static function workspace(string $slug): string
    {
        return self::host($slug);
    }

    /**
     * Absolute URL on a platform host, keeping the scheme and port of APP_URL
     * so local development on :8100 works the same as production.
     *
     * @return non-empty-string
     */
    public static function url(string $host, string $path = '/'): string
    {
        $appUrl = parse_url((string) config('app.url'));
        $scheme = $appUrl['scheme'] ?? 'https';
        $port = isset($appUrl['port']) ? ':'.$appUrl['port'] : '';

        $path = ltrim($path, '/');

        // Like Laravel's url(), the root has no trailing slash.
        return $scheme.'://'.$host.$port.($path === '' ? '' : '/'.$path);
    }

    /**
     * Labels that can never be a business workspace.
     *
     * @return list<string>
     */
    public static function reservedLabels(): array
    {
        /** @var list<string> $reserved */
        $reserved = config('platform.reserved_slugs', []);
        /** @var array<string, string> $hosts */
        $hosts = config('platform.hosts', []);

        return array_values(array_unique([...array_values($hosts), ...$reserved]));
    }

    public static function isReserved(string $label): bool
    {
        return in_array(strtolower($label), self::reservedLabels(), true);
    }

    /**
     * Classify a request host.
     *
     * @return array{type: HostType, label: ?string}
     */
    public static function classify(string $host): array
    {
        $host = strtolower(rtrim($host, '.'));
        $root = self::root();

        if ($host === $root) {
            return ['type' => HostType::Marketing, 'label' => null];
        }

        $suffix = '.'.$root;
        if (! str_ends_with($host, $suffix)) {
            return ['type' => HostType::Unknown, 'label' => null];
        }

        $label = substr($host, 0, -strlen($suffix));

        // Wildcard TLS covers one level only; deeper hosts are never served.
        if ($label === '' || str_contains($label, '.')) {
            return ['type' => HostType::Unknown, 'label' => null];
        }

        $type = match ($label) {
            config('platform.hosts.www') => HostType::Www,
            config('platform.hosts.auth') => HostType::Auth,
            config('platform.hosts.app') => HostType::App,
            config('platform.hosts.admin') => HostType::Admin,
            default => null,
        };

        if ($type !== null) {
            return ['type' => $type, 'label' => $label];
        }

        if (self::isReserved($label)) {
            return ['type' => HostType::Reserved, 'label' => $label];
        }

        if (! SlugRules::isWellFormed($label)) {
            return ['type' => HostType::Unknown, 'label' => null];
        }

        return ['type' => HostType::Workspace, 'label' => $label];
    }
}
