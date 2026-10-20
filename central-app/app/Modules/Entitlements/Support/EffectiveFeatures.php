<?php

namespace App\Modules\Entitlements\Support;

use App\Modules\Entitlements\Models\Entitlement;

/**
 * Which features of an application an organization may use, from its
 * current feature entitlement rows. First match wins:
 *
 *   1. an explicit denial by staff
 *   2. an explicit grant by staff
 *   3. a plan (subscription or trial)
 *   4. otherwise denied
 *
 * Expired and revoked rows never count, so a lapsed override falls back to
 * the plan. Several plans granting one feature combine to the largest limit
 * (no limit beats any). Products receive only the granted features.
 */
final class EffectiveFeatures
{
    /**
     * Granted features of the application, for the organization in the
     * current tenant context. Nothing without access to the application.
     *
     * @return list<array{key: string, limit: int|null}>
     */
    public static function granted(string $applicationId): array
    {
        $rows = Entitlement::query()
            ->current()
            ->where('application_id', $applicationId)
            ->whereNotNull('feature_key')
            ->get();

        $granted = [];

        foreach (self::resolve($rows) as $key => $decision) {
            if ($decision['granted']) {
                $granted[] = ['key' => $key, 'limit' => $decision['limit']];
            }
        }

        return $granted;
    }

    /**
     * The decision per feature key, and the row it rests on.
     *
     * @param  iterable<Entitlement>  $rows  current feature rows
     * @return array<string, array{granted: bool, source: string, limit: int|null, entitlement: Entitlement}>
     */
    public static function resolve(iterable $rows): array
    {
        $byKey = [];

        foreach ($rows as $row) {
            if ($row->feature_key !== null) {
                $byKey[$row->feature_key][] = $row;
            }
        }

        $decisions = [];

        foreach ($byKey as $key => $candidates) {
            $denial = self::latest(array_filter($candidates, fn (Entitlement $row) => $row->effect === Entitlement::DENY));
            $grant = self::latest(array_filter($candidates, fn (Entitlement $row) => $row->effect === Entitlement::GRANT && $row->source === 'manual'));
            $plans = array_values(array_filter($candidates, fn (Entitlement $row) => $row->effect === Entitlement::GRANT && $row->source !== 'manual'));

            $decisions[$key] = match (true) {
                $denial !== null => ['granted' => false, 'source' => 'denied', 'limit' => null, 'entitlement' => $denial],
                $grant !== null => ['granted' => true, 'source' => 'manual', 'limit' => $grant->limit_value, 'entitlement' => $grant],
                default => ['granted' => true, 'source' => $plans[0]->source, 'limit' => self::largestLimit($plans), 'entitlement' => $plans[0]],
            };
        }

        ksort($decisions);

        return $decisions;
    }

    /**
     * @param  array<int, Entitlement>  $rows
     */
    private static function latest(array $rows): ?Entitlement
    {
        usort($rows, fn (Entitlement $a, Entitlement $b) => $b->valid_from <=> $a->valid_from);

        return $rows[0] ?? null;
    }

    /**
     * @param  list<Entitlement>  $rows
     */
    private static function largestLimit(array $rows): ?int
    {
        $limits = array_map(fn (Entitlement $row) => $row->limit_value, $rows);

        return in_array(null, $limits, true) ? null : max($limits);
    }
}
