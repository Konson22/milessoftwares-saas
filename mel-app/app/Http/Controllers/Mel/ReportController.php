<?php

namespace App\Http\Controllers\Mel;

use App\Mel\Access;
use App\Mel\LinkedProjects;
use App\Mel\Models\Indicator;
use App\Mel\Models\Programme;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Models\Result;
use App\Mel\Models\Settings;
use App\Mel\Numbers;
use App\Mel\Performance;
use App\Mel\Reporting;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The six reports (ADR-0011 §7). Each is a page (printable) and an Excel
 * download (?format=xlsx) of the same figures. Actual values are verified
 * results only, at the stage the viewer may see; every report states its
 * filters, that stage and when and by whom it was generated.
 */
class ReportController extends Controller
{
    /** report => [title, needs mel.data.view] */
    public const REPORTS = [
        'iptt' => ['Indicator performance tracking table', false],
        'framework' => ['Results framework report', false],
        'target-actual' => ['Target vs actual', false],
        'completeness' => ['Reporting completeness', true],
        'verification' => ['Verification status', true],
        'programme' => ['Programme performance', false],
    ];

    public function __construct(private readonly Reporting $reporting) {}

    public function index(): Response
    {
        $internal = $this->access()->sees() === Access::SEE_ALL;

        return Inertia::render('mel/reports/index', [
            'reports' => collect(self::REPORTS)
                ->filter(fn (array $report) => ! $report[1] || $internal)
                ->map(fn (array $report, string $key) => ['key' => $key, 'title' => $report[0]])
                ->values(),
            'programmes' => $this->access()->programmes()->orderBy('code')->get()->map(fn (Programme $programme) => ['value' => $programme->id, 'label' => $programme->label()]),
            'sees' => $this->access()->sees(),
        ]);
    }

    public function show(Request $request, string $report): Response|StreamedResponse
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        abort_if(self::REPORTS[$report][1] && $this->access()->sees() !== Access::SEE_ALL, 403);

        $programme = $this->programme($request->string('programme')->toString());
        $filters = $this->filters($request, $programme);
        $data = match ($report) {
            'iptt' => $this->iptt($programme, $filters),
            'framework' => $this->framework($programme, $filters),
            'target-actual' => $this->targetActual($programme, $filters),
            'completeness', 'verification' => $this->completeness($programme, $filters),
            default => $this->programmeReport($programme, $filters),
        };

        $context = [
            'report' => ['key' => $report, 'title' => self::REPORTS[$report][0]],
            'programme' => ['id' => $programme->id, 'code' => $programme->code, 'name' => $programme->name, 'currency' => $programme->currency],
            'filters' => [
                'frequency' => $filters['frequency'],
                'from' => $filters['from'],
                'to' => $filters['to'],
                'unit' => $filters['unit']?->id,
                'period' => $filters['period']?->id,
            ],
            'options' => [
                'frequencies' => $programme->periods()->reorder('frequency')->distinct()->pluck('frequency')->values(),
                'units' => $this->reporting->units($programme)->map(fn (ProgrammeUnit $unit) => ['value' => $unit->id, 'label' => $unit->label()])->values(),
                'periods' => $this->reporting->periods($programme, $filters['frequency'])->map(fn (ReportingPeriod $period) => ['value' => $period->id, 'label' => $period->label])->values(),
            ],
            'generated' => [
                'at' => now()->toIso8601String(),
                'by' => $this->user()->name,
                'stage' => match ($this->access()->sees()) {
                    Access::SEE_PUBLISHED => 'Published results only',
                    null => 'No results',
                    default => 'Verified results (published or not)',
                },
                'scope' => $this->access()->unitIds($programme) === null ? null : 'Only the units you have access to',
                'thresholds' => ['onTrack' => Settings::settings()->on_track_percent, 'attention' => Settings::settings()->attention_percent],
            ],
            'can' => ['export' => $this->access()->can('mel.reports.export')],
        ];

        if ($request->string('format')->toString() === 'xlsx') {
            abort_unless($this->access()->can('mel.reports.export'), 403);

            return $this->export($report, $context, $data);
        }

        return Inertia::render('mel/reports/show', [...$context, 'data' => $data]);
    }

    /**
     * @return array{frequency: string, from: string|null, to: string|null, unit: ProgrammeUnit|null, period: ReportingPeriod|null, periods: Collection<int, ReportingPeriod>}
     */
    private function filters(Request $request, Programme $programme): array
    {
        $frequencies = Indicator::query()->where('programme_id', $programme->id)->where('status', '!=', Indicator::DRAFT)
            ->selectRaw('frequency, count(*) as total')->groupBy('frequency')->orderByDesc('total')->pluck('frequency');
        $frequency = in_array($request->string('frequency')->toString(), ReportingPeriod::FREQUENCIES, true)
            ? $request->string('frequency')->toString()
            : ($frequencies->first() ?? 'quarterly');
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        $unitId = $request->string('unit')->toString();
        $unit = $unitId === '' ? null : $this->reporting->units($programme)->firstWhere('id', $unitId);
        abort_if($unitId !== '' && $unit === null, 404);

        $periods = $this->reporting->periods($programme, $frequency)
            ->filter(fn (ReportingPeriod $period) => ($from === null || $period->ends_on->toDateString() >= $from) && ($to === null || $period->starts_on->toDateString() <= $to))
            ->values();

        $today = now()->toDateString();
        $period = $periods->firstWhere('id', $request->string('period')->toString())
            ?? $periods->filter(fn (ReportingPeriod $period) => $period->starts_on->toDateString() <= $today)->last()
            ?? $periods->first();

        return ['frequency' => $frequency, 'from' => $from, 'to' => $to, 'unit' => $unit, 'period' => $period, 'periods' => $periods];
    }

    /**
     * @param  array{frequency: string, from: string|null, to: string|null, unit: ProgrammeUnit|null, period: ReportingPeriod|null, periods: Collection<int, ReportingPeriod>}  $filters
     * @return array<string, mixed>
     */
    private function iptt(Programme $programme, array $filters): array
    {
        $ids = array_values($programme->indicators()->where('frequency', $filters['frequency'])->get()->map(fn (Indicator $indicator) => $indicator->id)->all());

        return [
            'periods' => $filters['periods']->map(fn (ReportingPeriod $period) => ['id' => $period->id, 'label' => $period->label])->values(),
            'rows' => $this->reporting->performance($programme, $filters['periods'], $ids, $filters['unit']?->id),
        ];
    }

    /**
     * @param  array{frequency: string, from: string|null, to: string|null, unit: ProgrammeUnit|null, period: ReportingPeriod|null, periods: Collection<int, ReportingPeriod>}  $filters
     * @return array<string, mixed>
     */
    private function framework(Programme $programme, array $filters): array
    {
        $performance = collect($this->reporting->performance($programme, $this->reporting->periods($programme), null, $filters['unit']?->id))
            ->keyBy('indicator.id');
        $results = FrameworkController::inTreeOrder($programme->results()->with(['level', 'indicators'])->whereNull('archived_at')->get());

        return [
            'results' => $results->map(fn (Result $result) => [
                'code' => $result->code,
                'level' => $result->level->name,
                'depth' => $result->level->depth,
                'statement' => $result->statement,
                'assumptions' => $result->assumptions,
                'meansOfVerification' => $result->means_of_verification,
                'indicators' => $result->indicators->sortBy('code')
                    ->map(function (Indicator $indicator) use ($performance) {
                        $row = $performance->get($indicator->id);
                        if ($row === null) {
                            return null;
                        }
                        $latest = collect($row['periods'])->filter(fn (array $cell) => $cell['actual'] !== null)->last();

                        return [
                            'indicator' => $row['indicator'],
                            'baseline' => $row['baseline'],
                            'latest' => $latest,
                            'toDate' => $row['toDate'],
                        ];
                    })->filter()->values(),
            ])->values(),
        ];
    }

    /**
     * @param  array{frequency: string, from: string|null, to: string|null, unit: ProgrammeUnit|null, period: ReportingPeriod|null, periods: Collection<int, ReportingPeriod>}  $filters
     * @return array<string, mixed>
     */
    private function targetActual(Programme $programme, array $filters): array
    {
        $period = $filters['period'];
        if ($period === null) {
            return ['period' => null, 'rows' => []];
        }

        $periods = collect([$period]);
        $byUnit = $this->reporting->units($programme)->whereNull('archived_at')
            ->mapWithKeys(fn (ProgrammeUnit $unit) => [$unit->id => collect($this->reporting->performance($programme, $periods, null, $unit->id))->keyBy('indicator.id')]);
        $units = $this->reporting->units($programme)->whereNull('archived_at')->keyBy('id');

        return [
            'period' => ['id' => $period->id, 'label' => $period->label],
            'rows' => collect($this->reporting->performance($programme, $periods))->map(fn (array $row) => [
                'indicator' => $row['indicator'],
                'programme' => $row['periods'][0] ?? null,
                'units' => $byUnit->map(fn (Collection $rows, string $unitId) => [
                    'unit' => $units->get($unitId)?->code,
                    'cell' => $rows->get($row['indicator']['id'])['periods'][0] ?? null,
                ])->values(),
            ])->filter(fn (array $row) => $row['programme'] !== null)->values(),
        ];
    }

    /**
     * @param  array{frequency: string, from: string|null, to: string|null, unit: ProgrammeUnit|null, period: ReportingPeriod|null, periods: Collection<int, ReportingPeriod>}  $filters
     * @return array<string, mixed>
     */
    private function completeness(Programme $programme, array $filters): array
    {
        $byPeriod = $filters['periods']->map(function (ReportingPeriod $period) use ($programme) {
            $result = $this->reporting->completeness($programme, collect([$period]));

            return ['period' => ['id' => $period->id, 'label' => $period->label, 'dueOn' => $period->due_on->toDateString()], 'totals' => $result['totals']];
        })->values();

        return [
            ...$this->reporting->completeness($programme, $filters['periods']),
            'byPeriod' => $byPeriod,
        ];
    }

    /**
     * Status counts per period: how many indicators are on track, need
     * attention, are off track or have no target or data. Counts of
     * indicators, never a combined score.
     *
     * @param  array{frequency: string, from: string|null, to: string|null, unit: ProgrammeUnit|null, period: ReportingPeriod|null, periods: Collection<int, ReportingPeriod>}  $filters
     * @return array<string, mixed>
     */
    private function programmeReport(Programme $programme, array $filters): array
    {
        $rows = $this->reporting->performance($programme, $filters['periods'], null, $filters['unit']?->id);
        $statuses = [Performance::ON_TRACK, Performance::ATTENTION, Performance::OFF_TRACK, Performance::NO_TARGET, Performance::NO_DATA];

        $byPeriod = $filters['periods']->map(function (ReportingPeriod $period) use ($rows, $statuses) {
            $counts = array_fill_keys($statuses, 0);
            foreach ($rows as $row) {
                foreach ($row['periods'] as $cell) {
                    if ($cell['periodId'] === $period->id) {
                        $counts[$cell['assessment']['status']]++;
                    }
                }
            }

            return ['period' => $period->label, 'counts' => $counts, 'indicators' => array_sum($counts)];
        })->filter(fn (array $row) => $row['indicators'] > 0)->values();

        $units = $this->reporting->units($programme);
        $projects = LinkedProjects::figures($units, $this->access());

        return [
            'byPeriod' => $byPeriod,
            'rows' => $rows,
            'projects' => $units->filter(fn (ProgrammeUnit $unit) => isset($projects[(string) $unit->linked_project_id]))->map(fn (ProgrammeUnit $unit) => [
                'unit' => $unit->code,
                'project' => $projects[(string) $unit->linked_project_id]['label'],
                'status' => $projects[(string) $unit->linked_project_id]['status'],
                'progressPercent' => $projects[(string) $unit->linked_project_id]['progressPercent'],
                'currency' => $projects[(string) $unit->linked_project_id]['currency'],
                'approvedBudgetMinor' => $projects[(string) $unit->linked_project_id]['approvedBudgetMinor'],
                'paidMinor' => $projects[(string) $unit->linked_project_id]['paidMinor'],
            ])->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $data
     */
    private function export(string $report, array $context, array $data): StreamedResponse
    {
        $notes = [
            "{$context['report']['title']} · {$context['programme']['code']} · {$context['programme']['name']}",
            "{$context['generated']['stage']}".($context['generated']['scope'] ? " · {$context['generated']['scope']}" : '').". Generated {$context['generated']['at']} by {$context['generated']['by']}.",
        ];
        $fmt = fn (?string $value) => $value === null ? null : Numbers::trim($value);
        $status = fn (?string $status) => $status === null ? null : str_replace('_', ' ', $status);
        $name = Str::slug("{$context['programme']['code']} {$report}").'.xlsx';

        $sheet = match ($report) {
            'iptt', 'programme' => [
                'title' => 'Performance',
                'columns' => ['Code', 'Indicator', 'Result', 'Measure', 'Unit', 'Version', 'Baseline', 'Period', 'Target', 'Target min', 'Target max', 'Actual', 'Numerator', 'Denominator', 'Units reported', '% of target', 'Progress from baseline %', 'Status'],
                'numeric' => [6, 8, 9, 10, 11, 12, 13, 15, 16],
                'rows' => items($data['rows'])->flatMap(fn (array $row) => items($row['periods'])->map(fn (array $cell) => [
                    $row['indicator']['code'], $row['indicator']['name'], $row['indicator']['resultCode'], $row['indicator']['measure'], $row['indicator']['unitLabel'], 'v'.$row['indicator']['version'],
                    $fmt($row['baseline']['value'] ?? null), $cell['period'],
                    $fmt($cell['target']['value'] ?? null), $fmt($cell['target']['min'] ?? null), $fmt($cell['target']['max'] ?? null),
                    $fmt($cell['actual']['value'] ?? null), $fmt($cell['actual']['numerator'] ?? null), $fmt($cell['actual']['denominator'] ?? null),
                    $cell['actual'] === null ? null : "{$cell['actual']['reported']} of {$cell['actual']['expected']}",
                    $fmt($cell['assessment']['achievement'] === null ? null : Numbers::round($cell['assessment']['achievement'], 1)),
                    $fmt($cell['assessment']['progress'] === null ? null : Numbers::round($cell['assessment']['progress'], 1)),
                    $status($cell['assessment']['status']),
                ]))->all(),
            ],
            'framework' => [
                'title' => 'Framework',
                'columns' => ['Level', 'Code', 'Result', 'Indicator code', 'Indicator', 'Baseline', 'Latest period', 'Latest target', 'Latest actual', 'Status', 'Means of verification', 'Assumptions'],
                'numeric' => [5, 7, 8],
                'rows' => items($data['results'])->flatMap(fn (array $result) => items($result['indicators'])->isEmpty()
                    ? [[$result['level'], $result['code'], $result['statement'], null, null, null, null, null, null, null, $result['meansOfVerification'], $result['assumptions']]]
                    : items($result['indicators'])->map(fn (array $indicator) => [
                        $result['level'], $result['code'], $result['statement'], $indicator['indicator']['code'], $indicator['indicator']['name'],
                        $fmt($indicator['baseline']['value'] ?? null), $indicator['latest']['period'] ?? null, $fmt($indicator['latest']['target']['value'] ?? null),
                        $fmt($indicator['latest']['actual']['value'] ?? null), $status($indicator['latest']['assessment']['status'] ?? null),
                        $result['meansOfVerification'], $result['assumptions'],
                    ])->all())->all(),
            ],
            'target-actual' => [
                'title' => 'Target vs actual',
                'columns' => ['Code', 'Indicator', 'For', 'Target', 'Actual', '% of target', 'Status'],
                'numeric' => [3, 4, 5],
                'rows' => items($data['rows'])->flatMap(fn (array $row) => collect([['unit' => 'Programme', 'cell' => $row['programme']], ...$row['units']])->map(fn (array $entry) => [
                    $row['indicator']['code'], $row['indicator']['name'], $entry['unit'],
                    $fmt($entry['cell']['target']['value'] ?? null), $fmt($entry['cell']['actual']['value'] ?? null),
                    $fmt(($entry['cell']['assessment']['achievement'] ?? null) === null ? null : Numbers::round($entry['cell']['assessment']['achievement'], 1)),
                    $status($entry['cell']['assessment']['status'] ?? null),
                ]))->all(),
            ],
            default => [
                'title' => 'By period',
                'columns' => ['Period', 'Due', 'Expected', 'Missing', 'Overdue', 'Draft', 'Submitted', 'Under review', 'Sent back', 'Verified', 'Published'],
                'numeric' => [2, 3, 4, 5, 6, 7, 8, 9, 10],
                'rows' => items($data['byPeriod'])->map(fn (array $row) => [
                    $row['period']['label'], $row['period']['dueOn'], $row['totals']['expected'], $row['totals']['missing'], $row['totals']['overdue'],
                    $row['totals']['draft'], $row['totals']['submitted'], $row['totals']['under_review'], $row['totals']['rejected'], $row['totals']['verified'], $row['totals']['published'],
                ])->all(),
            ],
        };

        return Spreadsheet::download($name, [[...$sheet, 'notes' => $notes]]);
    }
}
