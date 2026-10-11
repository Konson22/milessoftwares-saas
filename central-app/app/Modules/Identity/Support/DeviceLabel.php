<?php

namespace App\Modules\Identity\Support;

/**
 * Coarse "Browser on OS" label for session lists and alert emails. Display
 * only; never used for security decisions.
 */
final class DeviceLabel
{
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    private const SYSTEMS = [
        'Windows' => 'Windows',
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'Android' => 'Android',
        'Mac OS X' => 'macOS',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    public static function from(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return 'Unknown device';
        }

        $browser = self::firstMatch($userAgent, self::BROWSERS) ?? 'Unknown browser';
        $system = self::firstMatch($userAgent, self::SYSTEMS);

        return $system === null ? $browser : $browser.' on '.$system;
    }

    /**
     * @param  array<string, string>  $needles
     */
    private static function firstMatch(string $haystack, array $needles): ?string
    {
        foreach ($needles as $needle => $label) {
            if (str_contains($haystack, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
