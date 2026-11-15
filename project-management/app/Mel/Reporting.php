<?php

namespace App\Mel;

use App\Mel\Models\Indicator;
use App\Mel\Models\IndicatorBaseline;
use App\Mel\Models\IndicatorTarget;
use App\Mel\Models\IndicatorVersion;
use App\Mel\Models\Programme;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Models\Settings;
use App\Mel\Models\Submission;
use App\Mel\Models\VerifiedResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Figures for the dashboard and reports. Actual values come only from
 * verified_results (never drafts or submissions), and only those the
 * viewer may see: standing results for internal staff and evaluators,
 * published ones for donor viewers; within their units and periods.
 *
 * Each indicator is combined by its own rules (Calculator) and assessed on
 * its own (Performance). Nothing here adds different indicators together.
 *
 * @phpstan-type Actual array{value: string|null, numerator: string|null, denominator: string|null, complete: bool, reported: int, expected: int, mixedVersions?: bool}
 * @phpstan-type Target array{value: string|null, min: string|null, max: string|null}
 * @phpstan-type Assessment array{status: string, achievement: string|null, progress: string|null}
 * @phpstan-type Cell array{periodId: string, period: string, endsOn: string, target: Target|null, actual: Actual|null, assessment: Assessment}
 * @phpstan-type IndicatorSummary array{id: string, code: string, name: string, resultCode: string, type: string, measure: string, unitLabel: string|null, decimals: int, direction: string, frequency: string, version: int, status: string}
 * @phpstan-type PerformanceRow array{indicator: IndicatorSummary, baseline: array{value: string, measuredOn: string}|null, periods: list<Cell>, toDate: array{value: string|null, numerator: string|null, denominator: string|null, complete: bool}|null}
 */
class Reporting
{
    public function __construct(private readonly Access $access) {}

    /**
     * The periods of the programme the viewer may see, oldest first.
     *
     * @return Collection<int, ReportingPeriod>
     */
    public function periods(Programme $programme, ?string $frequency = null): Collection
    {
        return $programme->periods()
            ->when($frequency !== null, fn (Builder $query) => $query->where('frequency', $frequency))
            ->get()
            ->filter(fn (ReportingPeriod $period) => $this->access->reachesPeriod($programme, $period->starts_on, $period->ends_on))
            ->values()
            ->toBase();
    }

    /**
     * The units the viewer reaches, archived ones included (their history
     * stays in reports).
     *
     * @return Collection<int, ProgrammeUnit>
     */
    public function units(Programme $programme): Collection
    {
        $ids = $this->access->unitIds($programme);

        return $programme->units()->with('linkedProject')
            ->when($ids !== null, fn (Builder $query) => $query->whereIn('id', $ids ?? []))
            ->get()
            ->toBase();
    }

    /**
     * Verified results the viewer may see.
     *
     * @param  list<string>  $periodIds
     * @param  list<string>  $unitIds
     * @return Builder<VerifiedResult>
     */
    public function verified(Programme $programme, array $periodIds, array $unitIds): Builder
    {
        $sees = $this->access->sees();

        return VerifiedResult::query()
            ->standing()
            ->where('programme_id', $programme->id)
            ->whereIn('reporting_period_id', $periodIds)
            ->whereIn('unit_id', $unitIds)
            ->when($sees === Access::SEE_PUBLISHED, fn (Builder $query) => $query->whereNotNull('published_at'))
            ->when($sees === null, fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    /**
     * Indicator performance by period for the programme as a whole (the
     * units the viewer reaches): the Indicator Performance Tracking Table.
     *
     * @param  Collection<int, ReportingPeriod>|null  $periods  defaults to every visible period
     * @param  list<string>|null  $indicatorIds
     * @return list<PerformanceRow>
     */
    public function performance(Programme $programme, ?Collection $periods = null, ?array $indicatorIds = null, ?string $unitId = null): array
    {
        $settings = Settings::settings();
        $units = $this->units($programme)->when($unitId !== null, fn (Collection $units) => $units->where('id', $unitId)->values());
        $unitIds = array_values($units->map(fn (ProgrammeUnit $unit) => $unit->id)->all());
        $periods ??= $this->periods($programme);

        $indicators = $programme->indicators()
            ->with(['currentVersion', 'result'])
            ->where('status', '!=', Indicator::DRAFT)
            ->when($indicatorIds !== null, fn (Builder $query) => $query->whereIn('id', $indicatorIds ?? []))
            ->orderBy('code')
            ->get();

        $results = $this->verified($programme, array_values($periods->map(fn (ReportingPeriod $period) => $period->id)->all()), $unitIds)
            ->with('version')
            ->get()
            ->groupBy(fn (VerifiedResult $result) => $result->indicator_id.'|'.$result->reporting_period_id);

        $targets = IndicatorTarget::query()
            ->whereIn('indicator_id', $indicators->pluck('id'))
            ->whereNull('superseded_at')
            ->where('unit_id', $unitId)
            ->get()
            ->keyBy(fn (IndicatorTarget $target) => $target->indicator_id.'|'.$target->reporting_period_id);

        $baselines = IndicatorBaseline::query()
            ->whereIn('indicator_id', $indicators->pluck('id'))
            ->whereNull('superseded_at')
            ->where('unit_id', $unitId)
            ->get()
            ->keyBy('indicator_id');

        $rows = [];
        foreach ($indicators as $indicator) {
            $version = $indicator->currentVersion;
            if ($version === null) {
                continue;
            }

            $ownPeriods = $periods->where('frequency', $indicator->frequency)->values();
            $baseline = $baselines->get($indicator->id);
            $cells = [];
            $toDate = [];

            foreach ($ownPeriods as $period) {
                /** @var Collection<int, VerifiedResult> $periodResults */
                $periodResults = $results->get($indicator->id.'|'.$period->id, collect());
                $actual = $this->combineUnits($periodResults, count($unitIds));
                $target = $targets->get($indicator->id.'|'.$period->id);

                if ($actual !== null && $actual['value'] !== null) {
                    $toDate[] = $actual;
                }

                $cells[] = [
                    'periodId' => $period->id,
                    'period' => $period->label,
                    'endsOn' => $period->ends_on->toDateString(),
                    'target' => self::target($target),
                    'actual' => $actual,
                    'assessment' => Performance::assess(
                        $version->direction,
                        $actual['value'] ?? null,
                        $target?->value,
                        $baseline?->value,
                        $target?->min_value,
                        $target?->max_value,
                        $settings->on_track_percent,
                        $settings->attention_percent,
                    ),
                ];
            }

            $rows[] = [
                'indicator' => self::indicator($indicator, $version),
                'baseline' => $baseline === null ? null : ['value' => $baseline->value, 'measuredOn' => $baseline->measured_on->toDateString()],
                'periods' => $cells,
                'toDate' => $toDate === [] ? null : Calculator::overTime($version, $toDate),
            ];
        }

        return $rows;
    }

    /**
     * What has been reported for each indicator, period and unit, by stage:
     * the Reporting Completeness and Verification Status reports. Needs
     * mel.data.view (checked by the route).
     *
     * @param  Collection<int, ReportingPeriod>  $periods
     * @return array{units: list<array{id: string, code: string, name: string}>, rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function completeness(Programme $programme, Collection $periods): array
    {
        $units = $this->units($programme)->whereNull('archived_at')->values();
        $indicators = $programme->indicators()->with('currentVersion')->where('status', Indicator::ACTIVE)->orderBy('code')->get();

        $latest = Submission::query()
            ->where('programme_id', $programme->id)
            ->whereIn('reporting_period_id', $periods->pluck('id'))
            ->whereIn('unit_id', $units->pluck('id'))
            ->where('status', '!=', Submission::SUPERSEDED)
            ->orderBy('revision')
            ->get()
            // An open correction shows over the verified value it corrects.
            ->keyBy(fn (Submission $submission) => $submission->indicator_id.'|'.$submission->reporting_period_id.'|'.$submission->unit_id);

        $today = now()->toDateString();
        $totals = array_fill_keys(['expected', 'missing', 'overdue', 'draft', 'submitted', 'under_review', 'rejected', 'verified', 'published'], 0);
        $rows = [];

        foreach ($indicators as $indicator) {
            foreach ($periods->where('frequency', $indicator->frequency) as $period) {
                $cells = [];
                foreach ($units as $unit) {
                    $submission = $latest->get($indicator->id.'|'.$period->id.'|'.$unit->id);
                    $status = $submission->status ?? 'missing';
                    $overdue = $submission === null && $period->due_on->toDateString() < $today;
                    $totals['expected']++;
                    $totals[$status]++;
                    $totals['overdue'] += $overdue ? 1 : 0;
                    $cells[] = ['unitId' => $unit->id, 'status' => $status, 'overdue' => $overdue, 'submissionId' => $submission?->id];
                }

                $rows[] = [
                    'indicator' => ['id' => $indicator->id, 'code' => $indicator->code, 'name' => $indicator->currentVersion->name ?? ''],
                    'period' => ['id' => $period->id, 'label' => $period->label, 'dueOn' => $period->due_on->toDateString()],
                    'cells' => $cells,
                ];
            }
        }

        return [
            'units' => array_values($units->map(fn (ProgrammeUnit $unit) => ['id' => $unit->id, 'code' => $unit->code, 'name' => $unit->name])->all()),
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * Combines one period's unit results. Results under different versions
     * of the definition are not combined.
     *
     * @param  Collection<int, VerifiedResult>  $results
     * @return array{value: string|null, numerator: string|null, denominator: string|null, complete: bool, reported: int, expected: int, mixedVersions?: bool}|null
     */
    private function combineUnits(Collection $results, int $expected): ?array
    {
        if ($results->isEmpty()) {
            return null;
        }

        $versions = $results->pluck('indicator_version_id')->unique();
        if ($versions->count() > 1) {
            return ['value' => null, 'numerator' => null, 'denominator' => null, 'complete' => false, 'reported' => $results->count(), 'expected' => $expected, 'mixedVersions' => true];
        }

        /** @var VerifiedResult $first */
        $first = $results->first();
        $version = $first->version;
        $figures = array_values($results->map(fn (VerifiedResult $result) => ['value' => $result->value, 'numerator' => $result->numerator, 'denominator' => $result->denominator])->all());

        $combined = $results->count() === 1
            ? ['value' => Calculator::valueOf($version, $figures[0]), 'numerator' => $first->numerator, 'denominator' => $first->denominator, 'complete' => true]
            : Calculator::acrossUnits($version, $figures);

        if ($combined === null) {
            return ['value' => null, 'numerator' => null, 'denominator' => null, 'complete' => false, 'reported' => $results->count(), 'expected' => $expected];
        }

        return [...$combined, 'reported' => $results->count(), 'expected' => $expected];
    }

    /**
     * @return Target|null
     */
    private static function target(?IndicatorTarget $target): ?array
    {
        return $target === null ? null : ['value' => $target->value, 'min' => $target->min_value, 'max' => $target->max_value];
    }

    /**
     * @return IndicatorSummary
     */
    public static function indicator(Indicator $indicator, IndicatorVersion $version): array
    {
        return [
            'id' => $indicator->id,
            'code' => $indicator->code,
            'name' => $version->name,
            'resultCode' => $indicator->result->code,
            'type' => $version->type,
            'measure' => $version->measure,
            'unitLabel' => $version->unit_label,
            'decimals' => $version->decimals,
            'direction' => $version->direction,
            'frequency' => $indicator->frequency,
            'version' => $version->version,
            'status' => $indicator->status,
        ];
    }
}
