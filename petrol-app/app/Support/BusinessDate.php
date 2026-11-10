<?php

namespace App\Support;

use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * The station's calendar day follows the workspace time zone from provisioning,
 * not the server clock.
 */
final class BusinessDate
{
    public static function timezone(): string
    {
        $timezone = Workspace::query()->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    /**
     * Inclusive local dates as a UTC range for timestamp columns.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $from, string $to): array
    {
        $tz = self::timezone();

        return [
            CarbonImmutable::parse($from, $tz)->startOfDay()->utc(),
            CarbonImmutable::parse($to, $tz)->endOfDay()->utc(),
        ];
    }
}
