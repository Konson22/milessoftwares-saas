<?php

namespace App\Http\Controllers;

use App\Mel\Indicators;
use App\Mel\Measures;
use App\Mel\Numbers;
use App\Mel\Targets;
use App\Models\Dimension;
use App\Models\Indicator;
use App\Models\IndicatorBaseline;
use App\Models\IndicatorBreakdown;
use App\Models\IndicatorTarget;
use App\Models\IndicatorVersion;
use App\Models\Programme;
use App\Models\ReportingPeriod;
use App\Models\Result;
use App\Models\Submission;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Indicators: the list across programmes, the reference sheet of each,
 * their versioned definitions, baselines and targets.
 */
class IndicatorController extends Controller
{
    public function __construct(
        private readonly Indicators $indicators,
        private readonly Targets $targets,
    ) {}

    public function index(Request $request): Response
    {
        $programmeId = $request->string('programme')->toString() ?: null;
        $status = $request->string('status')->toString() ?: null;
        $search = trim($request->string('search')->toString());

        $indicators = Indicator::query()
            ->whereIn('programme_id', $this->access()->programmes()->whereNull('archived_at')->select('id'))
            ->with(['programme', 'result', 'currentVersion', 'responsible'])
            ->when($programmeId !== null, fn ($query) => $query->where('programme_id', $programmeId))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('code', 'like', "%{$search}%")
                ->orWhereHas('currentVersion', fn ($query) => $query->where('name', 'like', "%{$search}%"))))
            ->orderBy('code')
            ->limit(500)
            ->get();

        return Inertia::render('indicators/index', [
            'filters' => ['programme' => $programmeId, 'status' => $status, 'search' => $search],
            'programmes' => $this->access()->programmes()->whereNull('archived_at')->orderBy('code')->get()
                ->map(fn (Programme $programme) => ['value' => $programme->id, 'label' => $programme->label()]),
            'indicators' => $indicators->map(fn (Indicator $indicator) => [
                'id' => $indicator->id,
                'programmeId' => $indicator->programme_id,
                'programme' => $indicator->programme->code,
                'code' => $indicator->code,
                'name' => $indicator->currentVersion->name ?? '',
                'result' => $indicator->result->code,
                'type' => $indicator->currentVersion?->type,
                'measure' => $indicator->currentVersion?->measure,
                'frequency' => $indicator->frequency,
                'status' => $indicator->status,
                'responsible' => $indicator->responsible?->name,
            ]),
            'canCreate' => $this->access()->can('mel.frameworks.manage'),
        ]);
    }

    public function create(Request $request, string $programme): Response
    {
        $programme = $this->programme($programme);

        return Inertia::render('indicators/form', [
            'programme' => ['id' => $programme->id, 'code' => $programme->code, 'name' => $programme->name],
            'indicator' => null,
            'resultId' => $request->string('result')->toString() ?: null,
            ...$this->formOptions($programme),
        ]);
    }

    public function store(Request $request, string $programme): RedirectResponse
    {
        $programme = $this->programme($programme);
        $indicator = $this->indicators->create(
            $programme,
            $this->identity($request, $programme, null),
            $this->definition($request),
            $this->breakdowns($request),
            $this->user(),
        );

        return to_route('indicators.show', [$programme->id, $indicator->id])->with('success', 'Indicator created as a draft. Set its baseline and targets, then make it active.');
    }

    public function show(string $programme, string $indicator): Response
    {
        $programme = $this->programme($programme);
        $indicator = $programme->indicators()->with(['result.level', 'responsible', 'currentVersion.breakdowns.dimension', 'currentVersion.breakdowns.secondDimension', 'versions.author'])->findOrFail($indicator);
        $version = $indicator->currentVersion;
        abort_if($version === null, 404);
        $access = $this->access();
        $unitIds = $access->unitIds($programme);
        $units = $programme->units()->when($unitIds !== null, fn ($query) => $query->whereIn('id', $unitIds ?? []))->get();
        $periods = $programme->periods()->where('frequency', $indicator->frequency)->get()
            ->filter(fn (ReportingPeriod $period) => $access->reachesPeriod($programme, $period->starts_on, $period->ends_on))->values();
        $periodsForTargets = $programme->periods()->get()
            ->filter(fn (ReportingPeriod $period) => $access->reachesPeriod($programme, $period->starts_on, $period->ends_on))->values();
        $manages = $access->can('mel.frameworks.manage') && ! $programme->isArchived();
        $visibleUnit = fn (?string $unitId) => $unitId === null || $unitIds === null || in_array($unitId, $unitIds, true);

        $history = fn ($query) => $query->with(['author', 'unit'])->orderByRaw('superseded_at is null desc')->orderByDesc('superseded_at')->orderByDesc('created_at')->get()->filter(fn ($row) => $visibleUnit($row->unit_id))->values();

        return Inertia::render('indicators/show', [
            'programme' => ['id' => $programme->id, 'code' => $programme->code, 'name' => $programme->name, 'archived' => $programme->isArchived()],
            'indicator' => [
                'id' => $indicator->id,
                'code' => $indicator->code,
                'status' => $indicator->status,
                'frequency' => $indicator->frequency,
                'responsible' => $indicator->responsible?->name,
                'result' => ['code' => $indicator->result->code, 'statement' => $indicator->result->statement, 'level' => $indicator->result->level->name],
            ],
            'version' => self::version($version),
            'versions' => $indicator->versions->map(fn (IndicatorVersion $version) => [
                'version' => $version->version,
                'name' => $version->name,
                'measure' => $version->measure,
                'changeReason' => $version->change_reason,
                'author' => $version->author->name,
                'createdAt' => $version->created_at->toIso8601String(),
            ]),
            'units' => $units->map(fn ($unit) => ['value' => $unit->id, 'label' => $unit->label()])->values(),
            'periods' => $periods->map(fn (ReportingPeriod $period) => ['value' => $period->id, 'label' => $period->label])->values(),
            'targetPeriods' => $periodsForTargets->map(fn (ReportingPeriod $period) => ['value' => $period->id, 'label' => $period->label, 'frequency' => $period->frequency])->values(),
            'baselines' => $history(IndicatorBaseline::query()->where('indicator_id', $indicator->id))->map(fn (IndicatorBaseline $baseline) => [
                'id' => $baseline->id,
                'unitId' => $baseline->unit_id,
                'unit' => $baseline->unit?->code,
                'value' => $baseline->value,
                'numerator' => $baseline->numerator,
                'denominator' => $baseline->denominator,
                'measuredOn' => $baseline->measured_on->toDateString(),
                'source' => $baseline->source,
                'live' => $baseline->superseded_at === null,
                'changeReason' => $baseline->change_reason,
                'author' => $baseline->author->name,
                'createdAt' => $baseline->created_at->toIso8601String(),
            ]),
            'targets' => $history(IndicatorTarget::query()->where('indicator_id', $indicator->id)->with('period'))->map(fn (IndicatorTarget $target) => [
                'id' => $target->id,
                'unitId' => $target->unit_id,
                'unit' => $target->unit?->code,
                'periodId' => $target->reporting_period_id,
                'period' => $target->period->label,
                'value' => $target->value,
                'min' => $target->min_value,
                'max' => $target->max_value,
                'live' => $target->superseded_at === null,
                'changeReason' => $target->change_reason,
                'author' => $target->author->name,
                'createdAt' => $target->created_at->toIso8601String(),
            ]),
            'can' => ['manage' => $manages],
        ]);
    }

    public function edit(string $programme, string $indicator): Response
    {
        $programme = $this->programme($programme);
        $indicator = $programme->indicators()->with('currentVersion.breakdowns')->findOrFail($indicator);
        abort_if($indicator->currentVersion === null, 404);

        return Inertia::render('indicators/form', [
            'programme' => ['id' => $programme->id, 'code' => $programme->code, 'name' => $programme->name],
            'indicator' => [
                'id' => $indicator->id,
                'code' => $indicator->code,
                'resultId' => $indicator->result_id,
                'frequency' => $indicator->frequency,
                'responsibleId' => $indicator->responsible_id,
                'sortOrder' => $indicator->sort_order,
                'inUse' => $indicator->currentVersion->exists && Submission::query()->where('indicator_version_id', $indicator->currentVersion->id)->exists(),
                'definition' => self::version($indicator->currentVersion),
            ],
            'resultId' => null,
            ...$this->formOptions($programme),
        ]);
    }

    public function update(Request $request, string $programme, string $indicator): RedirectResponse
    {
        $programme = $this->programme($programme);
        $indicator = $programme->indicators()->findOrFail($indicator);
        $identity = $this->identity($request, $programme, $indicator);
        $definition = $this->definition($request);
        $breakdowns = $this->breakdowns($request);
        $reason = $request->validate(['change_reason' => ['nullable', 'string', 'max:2000']])['change_reason'] ?? null;

        DB::transaction(function () use ($indicator, $identity, $definition, $breakdowns, $reason) {
            $this->indicators->updateIdentity($indicator, $identity, $this->user());
            $this->indicators->revise($indicator, $definition, $breakdowns, $reason, $this->user());
        });

        return to_route('indicators.show', [$programme->id, $indicator->id])->with('success', 'Indicator saved.');
    }

    public function status(Request $request, string $programme, string $indicator): RedirectResponse
    {
        $programme = $this->programme($programme);
        $indicator = $programme->indicators()->findOrFail($indicator);
        $status = $request->validate(['status' => ['required', Rule::in([Indicator::DRAFT, Indicator::ACTIVE, Indicator::RETIRED])]])['status'];

        $this->indicators->setStatus($indicator, $status, $this->user());

        return back()->with('success', match ($status) {
            Indicator::ACTIVE => "{$indicator->code} is active: results can be entered.",
            Indicator::RETIRED => "{$indicator->code} is retired. Its results stay in reports.",
            default => "{$indicator->code} is back to draft.",
        });
    }

    public function baseline(Request $request, string $programme, string $indicator): RedirectResponse
    {
        $programme = $this->programme($programme);
        $indicator = $programme->indicators()->with('currentVersion')->findOrFail($indicator);
        $data = $request->validate([
            'unit_id' => ['nullable', 'uuid'],
            'value' => ['required'],
            'numerator' => ['nullable'],
            'denominator' => ['nullable'],
            'measured_on' => ['required', 'date'],
            'source' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->targets->setBaseline($indicator, $data['unit_id'] ?? null, $data, $this->user());

        return back()->with('success', 'Baseline saved.');
    }

    /**
     * Saves the target grid: one row per period (and unit), a value or a
     * range. Unchanged rows are left alone; changed ones need the reason.
     */
    public function targets(Request $request, string $programme, string $indicator): RedirectResponse
    {
        $programme = $this->programme($programme);
        $indicator = $programme->indicators()->with('currentVersion')->findOrFail($indicator);
        $data = $request->validate([
            'unit_id' => ['nullable', 'uuid'],
            'rows' => ['required', 'array', 'max:200'],
            'rows.*.period_id' => ['required', 'uuid'],
            'rows.*.value' => ['nullable'],
            'rows.*.min_value' => ['nullable'],
            'rows.*.max_value' => ['nullable'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $unitId = $data['unit_id'] ?? null;

        $live = IndicatorTarget::query()->where('indicator_id', $indicator->id)->where('unit_id', $unitId)->whereNull('superseded_at')->get()->keyBy('reporting_period_id');
        $changed = 0;

        DB::transaction(function () use ($programme, $indicator, $data, $unitId, $live, &$changed) {
            foreach ($data['rows'] as $row) {
                $period = $programme->periods()->whereKey($row['period_id'])->firstOrFail();
                $current = $live->get($period->id);
                $same = fn (?string $stored, mixed $input) => Numbers::parse($input) === ($stored === null ? null : Numbers::parse($stored));

                if ($same($current?->value, $row['value'] ?? null) && $same($current?->min_value, $row['min_value'] ?? null) && $same($current?->max_value, $row['max_value'] ?? null)) {
                    continue;
                }

                $this->targets->setTarget($indicator, $period, $unitId, [...$row, 'change_reason' => $data['change_reason'] ?? null], $this->user());
                $changed++;
            }
        });

        return back()->with('success', $changed === 0 ? 'No targets changed.' : "{$changed} targets saved.");
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(IndicatorVersion $version): array
    {
        return [
            'version' => $version->version,
            'name' => $version->name,
            'definition' => $version->definition,
            'type' => $version->type,
            'measure' => $version->measure,
            'unitLabel' => $version->unit_label,
            'decimals' => $version->decimals,
            'numeratorLabel' => $version->numerator_label,
            'denominatorLabel' => $version->denominator_label,
            'rateMultiplier' => $version->rate_multiplier,
            'calculationMethod' => $version->calculation_method,
            'direction' => $version->direction,
            'timeAggregation' => $version->time_aggregation,
            'unitAggregation' => $version->unit_aggregation,
            'dataSource' => $version->data_source,
            'collectionMethod' => $version->collection_method,
            'meansOfVerification' => $version->means_of_verification,
            'breakdowns' => $version->breakdowns->map(fn (IndicatorBreakdown $breakdown) => [
                'id' => $breakdown->id,
                'dimensionId' => $breakdown->dimension_id,
                'secondDimensionId' => $breakdown->second_dimension_id,
                'label' => $breakdown->label(),
                'rule' => $breakdown->rule,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Programme $programme): array
    {
        return [
            'results' => FrameworkController::inTreeOrder($programme->results()->with('level')->whereNull('archived_at')->get())
                ->map(fn (Result $result) => ['value' => $result->id, 'label' => "{$result->level->name} {$result->code} · ".str($result->statement)->limit(80)])->values(),
            'members' => WorkspaceMember::options(),
            'dimensions' => Dimension::query()->whereNull('archived_at')->orderBy('name')->get()->map(fn (Dimension $dimension) => ['value' => $dimension->id, 'label' => $dimension->name]),
            'frequencies' => self::options(ReportingPeriod::FREQUENCIES, fn ($value) => ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semiannual' => 'Half-yearly', 'annual' => 'Yearly', 'custom' => 'Custom periods'][$value]),
            'rules' => [
                'measures' => Measures::ALL,
                'types' => Measures::TYPES,
                'directions' => Measures::DIRECTIONS,
                'timeAggregations' => collect(Measures::ALL)->mapWithKeys(fn ($measure) => [$measure => Measures::timeAggregations($measure)]),
                'unitAggregations' => collect(Measures::ALL)->mapWithKeys(fn ($measure) => [$measure => Measures::unitAggregations($measure)]),
                'breakdownRules' => collect(Measures::ALL)->mapWithKeys(fn ($measure) => [$measure => Measures::breakdownRules($measure)]),
            ],
        ];
    }

    /**
     * @return array{result_id: string, code: string, frequency: string, responsible_id: string|null, sort_order: int}
     */
    private function identity(Request $request, Programme $programme, ?Indicator $indicator): array
    {
        $data = $request->validate([
            'result_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('indicators', 'code')->where('programme_id', $programme->id)->ignore($indicator?->id)],
            'frequency' => ['required', Rule::in(ReportingPeriod::FREQUENCIES)],
            'responsible_id' => ['nullable', 'uuid', WorkspaceMember::rule()],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], ['code.regex' => 'Letters, digits, ".", "-" and "_" only.']);

        return [
            'result_id' => $data['result_id'],
            'code' => strtoupper($data['code']),
            'frequency' => $data['frequency'],
            'responsible_id' => $data['responsible_id'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'definition' => ['nullable', 'string', 'max:10000'],
            'type' => ['required', Rule::in(Measures::TYPES)],
            'measure' => ['required', Rule::in(Measures::ALL)],
            'unit_label' => ['nullable', 'string', 'max:48'],
            'decimals' => ['required', 'integer', 'min:0', 'max:4'],
            'numerator_label' => ['nullable', 'string', 'max:255'],
            'denominator_label' => ['nullable', 'string', 'max:255'],
            'rate_multiplier' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'calculation_method' => ['nullable', 'string', 'max:5000'],
            'direction' => ['required', Rule::in(Measures::DIRECTIONS)],
            'time_aggregation' => ['required', 'string', 'max:16'],
            'unit_aggregation' => ['required', 'string', 'max:16'],
            'data_source' => ['nullable', 'string', 'max:255'],
            'collection_method' => ['nullable', 'string', 'max:255'],
            'means_of_verification' => ['nullable', 'string', 'max:5000'],
        ]);

        $fraction = Measures::isFraction($data['measure']);

        return [
            ...$data,
            'decimals' => $data['measure'] === 'count' || $data['measure'] === 'yes_no' ? 0 : (int) $data['decimals'],
            'numerator_label' => $fraction ? ($data['numerator_label'] ?? null) : null,
            'denominator_label' => $fraction ? ($data['denominator_label'] ?? null) : null,
            'rate_multiplier' => $data['measure'] === 'rate' ? ($data['rate_multiplier'] ?? null) : null,
        ];
    }

    /**
     * @return list<array{dimension_id: string, second_dimension_id: string|null, rule: string}>
     */
    private function breakdowns(Request $request): array
    {
        $data = $request->validate([
            'breakdowns' => ['array', 'max:6'],
            'breakdowns.*.dimension_id' => ['required', 'uuid', Rule::exists('dimensions', 'id')->where('organization_id', tenant_id())],
            'breakdowns.*.second_dimension_id' => ['nullable', 'uuid', Rule::exists('dimensions', 'id')->where('organization_id', tenant_id())],
            'breakdowns.*.rule' => ['required', Rule::in(Measures::BREAKDOWN_RULES)],
        ]);

        return array_values(array_map(fn (array $breakdown) => [
            'dimension_id' => $breakdown['dimension_id'],
            'second_dimension_id' => $breakdown['second_dimension_id'] ?? null,
            'rule' => $breakdown['rule'],
        ], $data['breakdowns'] ?? []));
    }
}
