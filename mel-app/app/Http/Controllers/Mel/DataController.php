<?php

namespace App\Http\Controllers\Mel;

use App\Mel\Calculator;
use App\Mel\Models\Dimension;
use App\Mel\Models\DimensionCategory;
use App\Mel\Models\EvidenceFile;
use App\Mel\Models\Indicator;
use App\Mel\Models\IndicatorBaseline;
use App\Mel\Models\IndicatorBreakdown;
use App\Mel\Models\IndicatorTarget;
use App\Mel\Models\Programme;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Models\Submission;
use App\Mel\Models\SubmissionValue;
use App\Mel\Submissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Data collection: what is due for a programme and period (indicators by
 * unit, with where each result stands) and the entry form.
 */
class DataController extends Controller
{
    public function __construct(private readonly Submissions $submissions) {}

    public function index(Request $request): Response
    {
        $access = $this->access();
        $programmes = $access->programmes()->whereNull('archived_at')->orderBy('code')->get();
        $programme = $programmes->firstWhere('id', $request->string('programme')->toString()) ?? $programmes->first();

        $periods = $programme === null ? collect() : $programme->periods()->get()
            ->filter(fn (ReportingPeriod $period) => $access->reachesPeriod($programme, $period->starts_on, $period->ends_on))->values();
        $today = now()->toDateString();
        $period = $periods->firstWhere('id', $request->string('period')->toString())
            // The open period due soonest, else the latest one.
            ?? $periods->where('status', ReportingPeriod::OPEN)->filter(fn (ReportingPeriod $period) => $period->starts_on->toDateString() <= $today)->sortBy('due_on')->first()
            ?? $periods->last();

        return Inertia::render('mel/data/index', [
            'programmes' => $programmes->map(fn (Programme $programme) => ['value' => $programme->id, 'label' => $programme->label()]),
            'periods' => $periods->map(fn (ReportingPeriod $period) => ['value' => $period->id, 'label' => $period->label.' ('.$period->frequency.')'])->values(),
            'selected' => ['programme' => $programme?->id, 'period' => $period?->id],
            'grid' => $programme !== null && $period !== null ? $this->grid($programme, $period) : null,
            'canImport' => $access->can('mel.data.import'),
        ]);
    }

    public function entry(string $programme, string $period, string $indicator, string $unit): Response
    {
        [$programme, $period, $indicator, $unit] = $this->resolve($programme, $period, $indicator, $unit);
        $version = $indicator->currentVersion;
        abort_if($version === null, 404);
        $version->load(['breakdowns.dimension.categories', 'breakdowns.secondDimension.categories']);

        $open = $this->submissions->open($indicator, $period, $unit);
        $standing = Submission::query()
            ->where('indicator_id', $indicator->id)->where('reporting_period_id', $period->id)->where('unit_id', $unit->id)
            ->whereIn('status', Submission::STANDING)->first();
        $target = IndicatorTarget::query()->where('indicator_id', $indicator->id)->where('reporting_period_id', $period->id)
            ->where('unit_id', $unit->id)->whereNull('superseded_at')->first();
        $baseline = IndicatorBaseline::query()->where('indicator_id', $indicator->id)->where('unit_id', $unit->id)->whereNull('superseded_at')->first();

        return Inertia::render('mel/data/entry', [
            'programme' => ['id' => $programme->id, 'code' => $programme->code, 'name' => $programme->name],
            'period' => ['id' => $period->id, 'label' => $period->label, 'dueOn' => $period->due_on->toDateString(), 'open' => $period->isOpen()],
            'unit' => ['id' => $unit->id, 'code' => $unit->code, 'name' => $unit->name],
            'indicator' => ['id' => $indicator->id, 'code' => $indicator->code, 'status' => $indicator->status],
            'version' => [
                ...IndicatorController::version($version),
                'breakdowns' => $version->breakdowns->map(fn (IndicatorBreakdown $breakdown) => [
                    'id' => $breakdown->id,
                    'label' => $breakdown->label(),
                    'rule' => $breakdown->rule,
                    'categories' => self::categories($breakdown->dimension),
                    'secondCategories' => $breakdown->secondDimension === null ? null : self::categories($breakdown->secondDimension),
                ])->values(),
            ],
            'target' => $target === null ? null : ['value' => $target->value, 'min' => $target->min_value, 'max' => $target->max_value],
            'baseline' => $baseline === null ? null : ['value' => $baseline->value, 'measuredOn' => $baseline->measured_on->toDateString()],
            'submission' => $open === null ? null : self::draft($open),
            'standing' => $standing === null ? null : [
                'id' => $standing->id,
                'status' => $standing->status,
                'revision' => $standing->revision,
                'value' => Calculator::valueOf($standing->version, $standing->only(['value', 'numerator', 'denominator'])),
            ],
        ]);
    }

    public function save(Request $request, string $programme, string $period, string $indicator, string $unit): RedirectResponse
    {
        [$programme, $period, $indicator, $unit] = $this->resolve($programme, $period, $indicator, $unit);
        $data = $request->validate([
            'value' => ['nullable'],
            'numerator' => ['nullable'],
            'denominator' => ['nullable'],
            'narrative' => ['nullable', 'string', 'max:10000'],
            'deviation_note' => ['nullable', 'string', 'max:5000'],
            'cells' => ['array', 'max:500'],
            'cells.*.breakdown_id' => ['required', 'uuid'],
            'cells.*.category_id' => ['required', 'uuid'],
            'cells.*.second_category_id' => ['nullable', 'uuid'],
            'cells.*.value' => ['nullable'],
            'cells.*.numerator' => ['nullable'],
            'cells.*.denominator' => ['nullable'],
            'submit' => ['boolean'],
        ]);

        $submitting = (bool) ($data['submit'] ?? false);

        // Saved and submitted together, or not at all.
        DB::transaction(function () use ($indicator, $period, $unit, $data, $submitting) {
            $submission = $this->submissions->save($indicator, $period, $unit, $data, $this->user());

            if ($submitting) {
                $this->submissions->submit($submission->fresh(['programme', 'period', 'version.breakdowns.dimension', 'version.breakdowns.secondDimension']) ?? $submission, $this->user());
            }
        });

        return to_route('mel.data.index', ['programme' => $programme->id, 'period' => $period->id])
            ->with('success', $submitting ? "{$indicator->code} for {$unit->code} submitted for review." : "{$indicator->code} for {$unit->code} saved as a draft.");
    }

    /**
     * @return array<string, mixed>
     */
    private function grid(Programme $programme, ReportingPeriod $period): array
    {
        $unitIds = $this->access()->unitIds($programme);
        $units = $programme->units()->whereNull('archived_at')
            ->when($unitIds !== null, fn ($query) => $query->whereIn('id', $unitIds ?? []))->get();
        $indicators = $programme->indicators()->with(['currentVersion', 'result'])
            ->where('status', Indicator::ACTIVE)->where('frequency', $period->frequency)->orderBy('code')->get();
        $submissions = Submission::query()
            ->where('reporting_period_id', $period->id)
            ->whereIn('unit_id', $units->pluck('id'))
            ->where('status', '!=', Submission::SUPERSEDED)
            ->with('version')
            ->orderBy('revision')
            ->get()
            ->keyBy(fn (Submission $submission) => $submission->indicator_id.'|'.$submission->unit_id);

        return [
            'period' => ['id' => $period->id, 'label' => $period->label, 'dueOn' => $period->due_on->toDateString(), 'open' => $period->isOpen(), 'overdue' => $period->due_on->isPast()],
            'units' => $units->map(fn (ProgrammeUnit $unit) => ['id' => $unit->id, 'code' => $unit->code, 'name' => $unit->name])->values(),
            'rows' => $indicators->map(fn (Indicator $indicator) => [
                'id' => $indicator->id,
                'code' => $indicator->code,
                'name' => $indicator->currentVersion->name ?? '',
                'measure' => $indicator->currentVersion?->measure,
                'decimals' => $indicator->currentVersion->decimals ?? 0,
                'unitLabel' => $indicator->currentVersion?->unit_label,
                'cells' => $units->map(function (ProgrammeUnit $unit) use ($indicator, $submissions) {
                    $submission = $submissions->get($indicator->id.'|'.$unit->id);

                    return [
                        'unitId' => $unit->id,
                        'status' => $submission->status ?? 'missing',
                        'submissionId' => $submission?->id,
                        'value' => $submission === null ? null : Calculator::valueOf($submission->version, $submission->only(['value', 'numerator', 'denominator'])),
                    ];
                })->values(),
            ])->values(),
            'canEnter' => $this->access()->can('mel.data.enter') && $period->isOpen(),
        ];
    }

    /**
     * @return array{0: Programme, 1: ReportingPeriod, 2: Indicator, 3: ProgrammeUnit}
     */
    private function resolve(string $programme, string $period, string $indicator, string $unit): array
    {
        $programme = $this->programme($programme);
        $period = $programme->periods()->findOrFail($period);
        $indicator = $programme->indicators()->with(['currentVersion.breakdowns', 'programme'])->findOrFail($indicator);
        $unit = $programme->units()->findOrFail($unit);

        abort_unless($this->access()->reachesUnit($programme, $unit->id), 404);
        abort_unless($this->access()->reachesPeriod($programme, $period->starts_on, $period->ends_on), 404);

        return [$programme, $period, $indicator, $unit];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private static function categories(Dimension $dimension): array
    {
        return array_values($dimension->categories->whereNull('archived_at')
            ->map(fn (DimensionCategory $category) => ['id' => $category->id, 'name' => $category->name])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function draft(Submission $submission): array
    {
        return [
            'id' => $submission->id,
            'status' => $submission->status,
            'revision' => $submission->revision,
            'value' => $submission->value,
            'numerator' => $submission->numerator,
            'denominator' => $submission->denominator,
            'narrative' => $submission->narrative,
            'deviationNote' => $submission->deviation_note,
            'cells' => $submission->values()->get()->map(fn (SubmissionValue $cell) => [
                'breakdownId' => $cell->breakdown_id,
                'categoryId' => $cell->category_id,
                'secondCategoryId' => $cell->second_category_id,
                'value' => $cell->value,
                'numerator' => $cell->numerator,
                'denominator' => $cell->denominator,
            ])->values(),
            'evidence' => $submission->evidence()->get()->map(fn (EvidenceFile $file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'size' => $file->size,
                'shareWithDonors' => $file->share_with_donors,
            ])->values(),
            // Why it was sent back, while it is.
            'sentBack' => $submission->status !== Submission::REJECTED ? null
                : $submission->decisions()->where('action', 'rejected')->latest('created_at')->first()?->comment,
        ];
    }
}
