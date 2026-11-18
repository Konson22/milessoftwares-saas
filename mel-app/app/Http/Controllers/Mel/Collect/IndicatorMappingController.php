<?php

namespace App\Http\Controllers\Mel\Collect;

use App\Collect\Calculations;
use App\Collect\Definition;
use App\Collect\Models\CalculationRow;
use App\Collect\Models\CalculationRun;
use App\Collect\Models\Form;
use App\Collect\Models\IndicatorMapping;
use App\Collect\Models\Submission;
use App\Http\Controllers\Mel\Controller;
use App\Mel\Models\Indicator;
use App\Mel\Models\Programme;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Numbers;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A form's indicators (ADR-0019 §26): which indicators its accepted
 * submissions feed, how, a preview for a period and unit, and sending the
 * value to M&E as a draft result. Calculations run when someone asks,
 * never on page load, except the one preview asked for.
 */
class IndicatorMappingController extends Controller
{
    public function __construct(private readonly Calculations $calculations) {}

    public function index(Request $request, string $form): Response
    {
        $form = $this->scope()->form($form);
        $form->load(['currentVersion', 'programme']);
        $programme = $form->programme_id === null ? null : $this->programme($form->programme_id);
        $definition = $form->currentVersion->definition ?? null;

        $mappings = IndicatorMapping::query()
            ->where('form_id', $form->id)
            ->with(['indicator.currentVersion', 'author', 'runs' => fn ($query) => $query->with(['period', 'unit', 'melSubmission'])->limit(50)])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->get();

        $preview = null;
        $mappingId = $request->query('mapping');
        $periodId = $request->query('period');
        $unitId = $request->query('unit');
        if (is_string($mappingId) && is_string($periodId) && is_string($unitId) && $programme !== null) {
            $mapping = $mappings->firstWhere('id', $mappingId);
            $period = ReportingPeriod::query()->where('programme_id', $programme->id)->find($periodId);
            $unit = $this->reachableUnit($programme, $unitId);
            if ($mapping instanceof IndicatorMapping && $period !== null && $unit !== null) {
                $result = $this->calculations->compute($mapping, $period, $unit);
                $preview = [
                    'mappingId' => $mapping->id,
                    'periodId' => $period->id,
                    'unitId' => $unit->id,
                    ...self::figures($mapping, $result),
                    'considered' => $result['considered'],
                    'accepted' => $result['accepted'],
                    'used' => $result['used'],
                    'excluded' => self::reasons($result['excluded']),
                ];
            }
        }

        return Inertia::render('mel/collect/forms/indicators', [
            'form' => [
                'id' => $form->id,
                'name' => $form->name,
                'status' => $form->status,
                'version' => $form->currentVersion?->number,
                'programme' => $programme === null ? null : ['id' => $programme->id, 'label' => $programme->label()],
            ],
            'indicators' => $programme === null ? [] : Indicator::query()
                ->where('programme_id', $programme->id)
                ->where('status', '<>', Indicator::RETIRED)
                ->with('currentVersion')
                ->orderBy('sort_order')->orderBy('code')
                ->get()
                ->filter(fn (Indicator $indicator) => $indicator->currentVersion !== null)
                ->map(fn (Indicator $indicator) => [
                    'id' => $indicator->id,
                    'label' => $indicator->label(),
                    'measure' => $indicator->currentVersion->measure,
                    'frequency' => $indicator->frequency,
                    'status' => $indicator->status,
                    'calculations' => array_values(array_filter(
                        IndicatorMapping::CALCULATIONS,
                        fn (string $calculation) => in_array($indicator->currentVersion->measure, IndicatorMapping::MEASURES[$calculation], true),
                    )),
                ])->values(),
            'questions' => $definition === null ? [] : array_values(array_map(
                fn (array $question) => [
                    'name' => $question['name'],
                    'label' => is_string($question['label'] ?? null) ? $question['label'] : $question['name'],
                    'type' => $question['type'],
                    'choices' => array_map(fn (string $name, string $label) => ['value' => $name, 'label' => $label], array_keys($choices = Definition::choices($definition, $question)), $choices),
                ],
                Definition::questions($definition),
            )),
            'periods' => $programme === null ? [] : $programme->periods()->orderBy('starts_on')->get()->map(fn (ReportingPeriod $period) => [
                'id' => $period->id,
                'label' => $period->label,
                'frequency' => $period->frequency,
                'open' => $period->isOpen(),
            ])->values(),
            'units' => $programme === null ? [] : $programme->units()->whereNull('archived_at')->orderBy('code')->get()
                ->filter(fn (ProgrammeUnit $unit) => $this->access()->reachesUnit($programme, $unit->id))
                ->map(fn (ProgrammeUnit $unit) => ['id' => $unit->id, 'label' => $unit->label()])->values(),
            'mappings' => $mappings->map(fn (IndicatorMapping $mapping) => [
                'id' => $mapping->id,
                'status' => $mapping->status,
                'indicator' => ['id' => $mapping->indicator_id, 'label' => $mapping->indicator->label(), 'measure' => $mapping->indicator->currentVersion->measure ?? null],
                'calculation' => $mapping->calculation,
                'question' => $mapping->question,
                'condition' => $mapping->condition,
                'numeratorCondition' => $mapping->numerator_condition,
                'distinctBy' => $mapping->distinct_by,
                'note' => $mapping->note,
                'author' => $mapping->author->name,
                'createdAt' => $mapping->created_at->toIso8601String(),
                'runs' => $mapping->runs->map(fn (CalculationRun $run) => self::run($run))->values(),
            ])->values(),
            'preview' => $preview,
            'can' => [
                'manage' => $this->access()->can('mel.frameworks.manage') && $form->status === Form::PUBLISHED,
                'send' => $this->access()->can('mel.data.enter'),
            ],
        ]);
    }

    public function store(Request $request, string $form): RedirectResponse
    {
        $form = $this->scope()->form($form);
        $form->load('currentVersion');
        $programme = $form->programme_id === null ? null : $this->programme($form->programme_id);
        $indicator = $programme === null ? null : Indicator::query()->where('programme_id', $programme->id)->with('currentVersion')->find((string) $request->input('indicator_id'));
        if ($indicator === null) {
            return back()->withErrors(['indicator_id' => 'Choose an indicator of the form\'s programme.']);
        }

        $this->calculations->create($form, $indicator, $this->input($request), $this->user());

        return back()->with('success', "The form now feeds {$indicator->code}.");
    }

    public function update(Request $request, string $mapping): RedirectResponse
    {
        $mapping = $this->mapping($mapping);
        $replaced = $mapping->runs()->exists();
        $this->calculations->update($mapping, $this->input($request), $this->user());

        return back()->with('success', $replaced
            ? 'Saved as a new version of the mapping. Results already sent keep the rules they were calculated with.'
            : 'Mapping saved.');
    }

    public function retire(string $mapping): RedirectResponse
    {
        $this->calculations->retire($this->mapping($mapping), $this->user());

        return back()->with('success', 'Mapping retired. Results already sent stay as they are.');
    }

    public function send(Request $request, string $mapping): RedirectResponse
    {
        $mapping = $this->mapping($mapping);
        $programme = $this->programme($mapping->indicator->programme_id);
        $period = ReportingPeriod::query()->where('programme_id', $programme->id)->findOrFail((string) $request->input('period_id'));
        $unit = $this->unit($programme, (string) $request->input('unit_id'));

        $run = $this->calculations->send($mapping, $period, $unit, $this->user());

        $value = $run->value !== null ? Numbers::trim($run->value) : Numbers::trim((string) $run->numerator).' / '.Numbers::trim((string) $run->denominator);

        return back()->with('success', "Sent {$value} to {$mapping->indicator->code} for {$period->label} as a draft result. Submit it for verification from Data entry.");
    }

    public function show(string $run): Response
    {
        /** @var CalculationRun $run */
        $run = CalculationRun::query()->with(['mapping.form', 'indicator.currentVersion', 'period', 'unit', 'melSubmission', 'author'])->findOrFail($run);
        $form = $this->scope()->form($run->mapping->form_id);
        $programme = $this->programme($run->indicator->programme_id);
        if (! $this->access()->reachesUnit($programme, $run->programme_unit_id)) {
            throw (new ModelNotFoundException)->setModel(CalculationRun::class, [$run->id]);
        }

        // An amount read from a personal question shows only to those who see personal data.
        $question = $run->mapping->question === null ? null : (Definition::questions($form->currentVersion->definition ?? [])[$run->mapping->question] ?? null);
        $hideAmounts = $question !== null && Definition::isPersonal($question) && ! $this->scope()->seesPersonalData();

        $rows = CalculationRow::query()
            ->where('run_id', $run->id)
            ->with(['submission.author', 'submission.version', 'revision'])
            ->get()
            ->sortBy(fn (CalculationRow $row) => $row->submission->completed_at)
            ->values();

        return Inertia::render('mel/collect/calculations/show', [
            'run' => [
                ...self::run($run),
                'indicator' => $run->indicator->label(),
                'form' => ['id' => $form->id, 'name' => $form->name],
                'calculation' => $run->mapping->calculation,
                'question' => $run->mapping->question,
                'distinctBy' => $run->mapping->distinct_by,
                'condition' => $run->mapping->condition,
                'numeratorCondition' => $run->mapping->numerator_condition,
                'considered' => $run->considered,
                'accepted' => $run->accepted,
                'excluded' => self::reasons($run->excluded),
            ],
            'rows' => $rows->map(fn (CalculationRow $row) => [
                'submissionId' => $row->submission_id,
                'label' => substr($row->submission_id, 0, 8),
                'author' => $row->submission->author->name,
                'completedAt' => $row->submission->completed_at->toIso8601String(),
                'version' => $row->submission->version->number,
                'revision' => $row->revision?->number,
                'statusNow' => $row->submission->status,
                'amount' => $hideAmounts ? null : Numbers::trim($row->amount),
                'inNumerator' => $row->in_numerator,
                'standing' => $row->standing,
            ])->values(),
            'amountsHidden' => $hideAmounts,
            'questions' => array_values(array_map(
                fn (array $question) => ['name' => $question['name'], 'label' => is_string($question['label'] ?? null) ? $question['label'] : $question['name'], 'type' => $question['type'], 'choices' => array_map(
                    fn (string $name, string $label) => ['value' => $name, 'label' => $label],
                    array_keys($choices = Definition::choices($form->currentVersion->definition ?? [], $question)),
                    $choices,
                )],
                Definition::questions($form->currentVersion->definition ?? []),
            )),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function run(CalculationRun $run): array
    {
        // Accepted since it was sent: a reason to recalculate.
        $newer = $run->status === CalculationRun::SENT
            ? Submission::query()
                ->where('form_id', $run->mapping->form_id ?? IndicatorMapping::query()->whereKey($run->mapping_id)->value('form_id'))
                ->where('programme_unit_id', $run->programme_unit_id)
                ->where('status', Submission::ACCEPTED)
                ->where('reviewed_at', '>', $run->created_at)
                ->where('completed_at', '>=', $run->period->starts_on->startOfDay())
                ->where('completed_at', '<', $run->period->ends_on->addDay()->startOfDay())
                ->count()
            : 0;

        return [
            'id' => $run->id,
            'period' => ['id' => $run->reporting_period_id, 'label' => $run->period->label],
            'unit' => ['id' => $run->programme_unit_id, 'label' => $run->unit->label()],
            'value' => $run->value === null ? null : Numbers::trim($run->value),
            'numerator' => $run->numerator === null ? null : Numbers::trim($run->numerator),
            'denominator' => $run->denominator === null ? null : Numbers::trim($run->denominator),
            'used' => $run->used,
            'status' => $run->status,
            'result' => $run->melSubmission === null ? null : [
                'id' => $run->melSubmission->id,
                'status' => $run->melSubmission->status,
                // Still the run behind the M&E result, or replaced since.
                'fromThisRun' => $run->melSubmission->source_reference === $run->id,
            ],
            'flag' => $run->flagged_at === null ? null : $run->flag_reason,
            'newer' => $newer,
            'createdAt' => $run->created_at->toIso8601String(),
            'author' => $run->author->name ?? null,
        ];
    }

    /**
     * @param  array{value: string|null, numerator: string|null, denominator: string|null}  $result
     * @return array{value: string|null, numerator: string|null, denominator: string|null}
     */
    private static function figures(IndicatorMapping $mapping, array $result): array
    {
        return [
            'value' => $result['value'] === null ? null : Numbers::trim($result['value']),
            'numerator' => $result['numerator'] === null ? null : Numbers::trim($result['numerator']),
            'denominator' => $result['denominator'] === null ? null : Numbers::trim($result['denominator']),
        ];
    }

    /**
     * @param  array<string, int>  $excluded
     * @return list<array{reason: string, label: string, count: int}>
     */
    private static function reasons(array $excluded): array
    {
        $list = [];
        foreach ($excluded as $reason => $count) {
            $list[] = ['reason' => $reason, 'label' => Calculations::REASONS[$reason] ?? $reason, 'count' => $count];
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return [
            'calculation' => $request->input('calculation'),
            'question' => $request->input('question'),
            'condition' => $request->input('condition'),
            'numerator_condition' => $request->input('numerator_condition'),
            'distinct_by' => $request->input('distinct_by'),
            'note' => $request->input('note'),
        ];
    }

    /**
     * A mapping of a form the member reaches, or 404.
     */
    private function mapping(string $id): IndicatorMapping
    {
        $mapping = IndicatorMapping::query()->with(['form.currentVersion', 'indicator.currentVersion'])->findOrFail($id);
        $this->scope()->form($mapping->form_id);
        $this->programme($mapping->indicator->programme_id);

        return $mapping;
    }

    private function reachableUnit(Programme $programme, string $id): ?ProgrammeUnit
    {
        $unit = ProgrammeUnit::query()->where('programme_id', $programme->id)->find($id);

        return $unit !== null && $this->access()->reachesUnit($programme, $unit->id) ? $unit : null;
    }

    /**
     * A unit of the programme the member reaches, or 404.
     */
    private function unit(Programme $programme, string $id): ProgrammeUnit
    {
        return $this->reachableUnit($programme, $id) ?? throw (new ModelNotFoundException)->setModel(ProgrammeUnit::class, [$id]);
    }
}
