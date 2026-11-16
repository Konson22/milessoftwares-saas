<?php

namespace App\Collaboration;

use App\Collaboration\Models\Report;
use Illuminate\Support\Str;
use Miles\PlatformClient\Integration\IntegrationClient;
use Miles\PlatformClient\Integration\IntegrationResult;
use Miles\PlatformClient\Integration\IntegrationStatus;

/**
 * The M&E results a partner report records at submission (ADR-0019 §20),
 * read live from the M&E application for the project's owner organization.
 *
 * Disclosure policy (ADR-0019 §20.6), least privilege at both ends:
 *   - M&E sends only verified, published, standing results of active or
 *     retired indicators, from units an M&E manager has marked to report to
 *     this project, for periods overlapping the report period (required).
 *   - The read is the owner organization's, made by Projects itself, not on
 *     behalf of the submitting member: a partner gains no access to M&E.
 *   - The results are recorded with the version, each with the audience M&E
 *     released it to (owner, partner, donors) and the partner organization it
 *     names, and shown per result by Reports::visibleMelResults (ADR-0019
 *     §24): the owner always; the named partner and its reviewer when that
 *     partner submitted the report; donors on the published report when M&E
 *     released the result to donors. Anything else sees none of them.
 *   - Indicator ids, units, values and verification dates as M&E gave them,
 *     each on its own, never added up; evidence names only; no Data
 *     Collection submissions, narratives or personal data.
 *
 * The read happens before the submission transaction and never blocks the
 * submission: when M&E cannot answer, the report records why (source.status)
 * and no results. "No results" is only ever recorded when M&E answered so.
 */
final class MelResults
{
    public const PAIR = 'mel';

    /** Who M&E released a result to (ADR-0019 §24): the owner only, a named partner too, donors on publication too. */
    public const AUDIENCES = ['owner', 'partner', 'donors'];

    /** M&E answered with results. */
    public const CAPTURED = 'captured';

    /** M&E answered: units are linked to the project, nothing published for the period. */
    public const NO_RESULTS = 'no_results';

    /** M&E answered: no unit is linked to this project. */
    public const UNLINKED = 'unlinked';

    /** M&E answered: units are linked, none marked to report to the project. */
    public const NOT_SHARED = 'not_shared';

    /** The report has no period, so nothing is asked of M&E. */
    public const NO_PERIOD = 'no_period';

    /** This deployment does not connect to M&E. */
    public const OFF = 'off';

    /** The organization has no M&E subscription (or not Projects). */
    public const NOT_ENTITLED = 'not_entitled';

    /** The subscription ran out. */
    public const EXPIRED = 'subscription_expired';

    /** The organization has M&E but never opened it. */
    public const NOT_SET_UP = 'not_set_up';

    public const DENIED = 'denied';

    public const UNAVAILABLE = 'unavailable';

    public const TIMEOUT = 'timeout';

    public const ERROR = 'error';

    public function __construct(private readonly IntegrationClient $client) {}

    /**
     * @return array{results: list<array<string, mixed>>, source: array{status: string, retrievedAt: string|null, correlationId: string|null, error: string|null, truncated: bool}}
     */
    public function forReport(Report $report): array
    {
        if (! $this->client->configured(self::PAIR)) {
            return self::outcome(self::OFF, null);
        }

        // No open-ended reads: results only for the period the report covers.
        if ($report->period_start === null || $report->period_end === null) {
            return self::outcome(self::NO_PERIOD, null);
        }
        $query = ['from' => $report->period_start->toDateString(), 'to' => $report->period_end->toDateString()];
        $result = $this->client->get(self::PAIR, $report->organization_id, 'projects/'.rawurlencode($report->project_id).'/verified-results', $query);

        if (! $result->ok()) {
            return self::outcome(self::failure($result), $result);
        }
        if (($result->data['linked'] ?? null) !== true) {
            return self::outcome(self::UNLINKED, $result);
        }
        if (($result->data['shared'] ?? 0) === 0) {
            return self::outcome(self::NOT_SHARED, $result);
        }

        $rows = is_array($result->data['data'] ?? null) ? $result->data['data'] : [];
        $results = array_values(array_map(self::result(...), array_filter($rows, fn (mixed $row) => is_array($row) && isset($row['id']))));

        return self::outcome($results === [] ? self::NO_RESULTS : self::CAPTURED, $result, $results, ($result->data['truncated'] ?? false) === true);
    }

    private static function failure(IntegrationResult $result): string
    {
        return match ($result->status) {
            IntegrationStatus::NotEntitled => $result->error === 'entitlement_expired' ? self::EXPIRED : self::NOT_ENTITLED,
            IntegrationStatus::NotFound => $result->error === 'not_provisioned' ? self::NOT_SET_UP : ($result->error === 'not_entitled' ? self::NOT_ENTITLED : self::ERROR),
            IntegrationStatus::Denied => self::DENIED,
            IntegrationStatus::Timeout => self::TIMEOUT,
            IntegrationStatus::Unavailable => self::UNAVAILABLE,
            IntegrationStatus::NotConfigured => self::OFF,
            default => self::ERROR,
        };
    }

    /**
     * One result as recorded in the snapshot. The first keys are those
     * snapshots have always used (the report page shows them).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function result(array $row): array
    {
        $text = fn (string $key): ?string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : null;

        return [
            'indicator' => trim(($text('indicatorCode') ?? '').' · '.($text('indicator') ?? ''), ' ·'),
            'unit' => $text('unit'),
            'period' => $text('period'),
            'measure' => $text('measure'),
            'unitLabel' => $text('unitLabel'),
            'value' => $text('value'),
            'numerator' => $text('numerator'),
            'denominator' => $text('denominator'),
            'published' => true,
            'resultId' => $text('id'),
            'indicatorId' => $text('indicatorId'),
            'indicatorVersionId' => $text('indicatorVersionId'),
            'unitId' => $text('unitId'),
            'periodId' => $text('periodId'),
            'periodStartsOn' => $text('periodStartsOn'),
            'periodEndsOn' => $text('periodEndsOn'),
            'verifiedAt' => $text('verifiedAt'),
            'publishedAt' => $text('publishedAt'),
            // Who M&E released it to (Reports::visibleMelResults); anything
            // unknown is the owner only.
            'audience' => in_array($row['audience'] ?? null, self::AUDIENCES, true) ? $row['audience'] : 'owner',
            'partnerOrganizationId' => is_string($row['partnerOrganizationId'] ?? null) && Str::isUuid($row['partnerOrganizationId']) ? strtolower($row['partnerOrganizationId']) : null,
            'evidence' => array_values(array_map(fn (mixed $file) => [
                'name' => is_array($file) && is_scalar($file['name'] ?? null) ? (string) $file['name'] : '',
                'mimeType' => is_array($file) && is_scalar($file['mimeType'] ?? null) ? (string) $file['mimeType'] : null,
                'size' => is_array($file) && is_numeric($file['size'] ?? null) ? (int) $file['size'] : null,
            ], is_array($row['evidence'] ?? null) ? $row['evidence'] : [])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array{results: list<array<string, mixed>>, source: array{status: string, retrievedAt: string|null, correlationId: string|null, error: string|null, truncated: bool}}
     */
    private static function outcome(string $status, ?IntegrationResult $result, array $results = [], bool $truncated = false): array
    {
        return [
            'results' => $results,
            'source' => [
                'status' => $status,
                'retrievedAt' => $result?->retrievedAt->format(DATE_ATOM),
                'correlationId' => $result?->correlationId,
                'error' => $result !== null && ! $result->ok() ? $result->error : null,
                'truncated' => $truncated,
            ],
        ];
    }
}
