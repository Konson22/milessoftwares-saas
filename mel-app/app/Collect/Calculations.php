<?php

namespace App\Collect;

use App\Collect\Models\CalculationRow;
use App\Collect\Models\CalculationRun;
use App\Collect\Models\Form;
use App\Collect\Models\IndicatorMapping;
use App\Collect\Models\Submission;
use App\Mel\AuditTrail;
use App\Mel\Models\Indicator;
use App\Mel\Models\ProgrammeUnit;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Models\Submission as MelSubmission;
use App\Mel\Numbers;
use App\Mel\Submissions as MelSubmissions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Collected data → indicator values (ADR-0012 §11, ADR-0019 §26).
 *
 *   accepted submissions → mapping → calculation run → M&E draft result
 *   → M&E's own verification → verified result
 *
 * Only accepted submissions count, by their current answers (the latest
 * correction). A run is worked out when someone asks, never on page load,
 * and sending it writes or updates the M&E draft for that indicator,
 * period and unit through M&E's own Submissions: accepting survey data
 * never makes a verified result by itself. Each run keeps the
 * submissions it counted, and "standing" rows make sure one submission
 * counts at most once per indicator. When a counted submission is
 * reopened or corrected, its run is flagged so someone recalculates.
 */
class Calculations
{
    /** Why a submission was left out, in the words the pages use. */
    public const REASONS = [
        'not_accepted' => 'Not accepted (yet)',
        'purged' => 'Answers removed by the retention rule',
        'question_missing' => 'The question is not in the version it was filled in on',
        'filtered_out' => 'Does not meet the mapping\'s condition',
        'not_answered' => 'The question was not answered',
        'no_identifier' => 'No identifier to tell repeat visits apart',
        'repeat_visit' => 'A later visit with the same identifier counts instead',
        'counted_elsewhere' => 'Already counted for this indicator in another period or unit',
    ];

    /** Operators a mapping's condition can use (the form engine's own). */
    private const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'answered', 'not_answered', 'includes', 'in'];

    /** The question types a sum or an average reads. */
    private const NUMERIC = ['integer', 'decimal'];

    /** The question types that can tell repeat visits apart. */
    private const IDENTIFIERS = ['text', 'integer', 'select_one'];

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly MelSubmissions $melSubmissions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Form $form, Indicator $indicator, array $data, User $user, ?IndicatorMapping $supersedes = null): IndicatorMapping
    {
        $clean = $this->validated($form, $indicator, $data, $supersedes);

        return DB::transaction(function () use ($form, $indicator, $clean, $user, $supersedes) {
            $mapping = IndicatorMapping::query()->create([
                ...$clean,
                'form_id' => $form->id,
                'indicator_id' => $indicator->id,
                'status' => IndicatorMapping::ACTIVE,
                'supersedes_id' => $supersedes?->id,
                'created_by' => $user->id,
            ]);
            $this->audit->record($supersedes === null ? 'mapping.created' : 'mapping.changed', $mapping, $user, [
                'calculation' => $mapping->calculation,
                'question' => $mapping->question,
            ]);

            return $mapping;
        });
    }

    /**
     * Edits a mapping no run used yet; once one did, the change is a new
     * mapping that supersedes it, so every sent run keeps the rules it was
     * worked out with.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(IndicatorMapping $mapping, array $data, User $user): IndicatorMapping
    {
        if (! $mapping->isActive()) {
            throw ValidationException::withMessages(['mapping' => 'This mapping was retired. Add a new one.']);
        }

        if ($mapping->runs()->exists()) {
            return DB::transaction(function () use ($mapping, $data, $user) {
                $this->retireRow($mapping, $user);

                return $this->create($mapping->form, $mapping->indicator, $data, $user, $mapping);
            });
        }

        $clean = $this->validated($mapping->form, $mapping->indicator, $data, $mapping);

        return DB::transaction(function () use ($mapping, $clean, $user) {
            $mapping->update($clean);
            $this->audit->record('mapping.updated', $mapping, $user, ['calculation' => $mapping->calculation]);

            return $mapping;
        });
    }

    public function retire(IndicatorMapping $mapping, User $user): void
    {
        if (! $mapping->isActive()) {
            return;
        }

        DB::transaction(function () use ($mapping, $user) {
            $this->retireRow($mapping, $user);
            $this->audit->record('mapping.retired', $mapping, $user);
        });
    }

    /**
     * Works out the value for a period and unit without saving anything.
     *
     * @return array{value: string|null, numerator: string|null, denominator: string|null, considered: int, accepted: int, used: int, excluded: array<string, int>, rows: list<array{submission: Submission, revision_id: string|null, amount: string, in_numerator: bool|null}>}
     */
    public function compute(IndicatorMapping $mapping, ReportingPeriod $period, ProgrammeUnit $unit): array
    {
        $submissions = Submission::query()
            ->with(['version', 'currentRevision'])
            ->where('form_id', $mapping->form_id)
            ->where('programme_unit_id', $unit->id)
            ->where('completed_at', '>=', $period->starts_on->startOfDay())
            ->where('completed_at', '<', $period->ends_on->addDay()->startOfDay())
            ->where('status', '<>', Submission::RECEIVING)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();

        // Counted for this indicator by a run of another period or unit.
        $elsewhere = CalculationRow::query()
            ->where('indicator_id', $mapping->indicator_id)
            ->where('standing', true)
            ->whereIn('submission_id', $submissions->pluck('id')->all())
            ->whereHas('run', fn ($query) => $query->where(fn ($other) => $other
                ->where('reporting_period_id', '<>', $period->id)
                ->orWhere('programme_unit_id', '<>', $unit->id)))
            ->pluck('submission_id')
            ->flip();

        $excluded = [];
        $exclude = function (string $reason) use (&$excluded): void {
            $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
        };

        $accepted = 0;
        /** @var array<string, array{submission: Submission, answers: array<string, mixed>}> $kept */
        $kept = [];
        foreach ($submissions as $submission) {
            if ($submission->status !== Submission::ACCEPTED) {
                $exclude('not_accepted');

                continue;
            }
            $accepted++;

            if ($submission->purged_at !== null) {
                $exclude('purged');

                continue;
            }
            if ($elsewhere->has($submission->id)) {
                $exclude('counted_elsewhere');

                continue;
            }

            $questions = Definition::questions($submission->version->definition);
            $needed = array_filter([$mapping->question, $mapping->distinct_by]);
            if (array_diff($needed, array_keys($questions)) !== []) {
                $exclude('question_missing');

                continue;
            }

            $answers = $submission->currentAnswers();
            if ($mapping->condition !== null && ! Rules::test($mapping->condition, $answers)) {
                $exclude('filtered_out');

                continue;
            }
            if ($mapping->question !== null && Numbers::parse($answers[$mapping->question] ?? null) === null) {
                $exclude('not_answered');

                continue;
            }

            $key = $submission->id;
            if ($mapping->distinct_by !== null) {
                $identifier = $answers[$mapping->distinct_by] ?? null;
                $identifier = is_scalar($identifier) ? mb_strtolower(trim((string) $identifier)) : '';
                if ($identifier === '') {
                    $exclude('no_identifier');

                    continue;
                }
                $key = 'id:'.$identifier;
                // Ordered by completion: a later visit replaces the earlier one.
                if (isset($kept[$key])) {
                    $exclude('repeat_visit');
                }
            }
            $kept[$key] = ['submission' => $submission, 'answers' => $answers];
        }

        $rows = [];
        foreach ($kept as ['submission' => $submission, 'answers' => $answers]) {
            $rows[] = [
                'submission' => $submission,
                'revision_id' => $submission->current_revision_id,
                'amount' => $mapping->question === null ? '1' : (string) Numbers::parse($answers[$mapping->question]),
                'in_numerator' => $mapping->calculation === IndicatorMapping::PERCENTAGE
                    ? Rules::test($mapping->numerator_condition, $answers)
                    : null,
            ];
        }

        $total = Numbers::sum(array_map(fn (array $row) => $row['amount'], $rows));
        $count = (string) count($rows);
        [$value, $numerator, $denominator] = match ($mapping->calculation) {
            IndicatorMapping::COUNT => [$count, null, null],
            IndicatorMapping::SUM => [$total, null, null],
            IndicatorMapping::AVERAGE => [null, $total, $count],
            IndicatorMapping::PERCENTAGE => [null, (string) count(array_filter($rows, fn (array $row) => $row['in_numerator'] === true)), $count],
            default => throw new \LogicException("Unknown calculation {$mapping->calculation}."),
        };

        ksort($excluded);

        return [
            'value' => $value,
            'numerator' => $numerator,
            'denominator' => $denominator,
            'considered' => $submissions->count(),
            'accepted' => $accepted,
            'used' => count($rows),
            'excluded' => $excluded,
            'rows' => $rows,
        ];
    }

    /**
     * Works the value out again and sends it to M&E as the draft result for
     * the indicator, period and unit, replacing an earlier run's. It never
     * touches a result someone entered by hand, or one waiting for
     * verification.
     */
    public function send(IndicatorMapping $mapping, ReportingPeriod $period, ProgrammeUnit $unit, User $user): CalculationRun
    {
        return DB::transaction(function () use ($mapping, $period, $unit, $user) {
            // One run at a time per mapping: two people pressing Send at once
            // queue here, and the second recalculates after the first.
            $mapping = IndicatorMapping::query()->whereKey($mapping->id)->lockForUpdate()->firstOrFail();
            if (! $mapping->isActive()) {
                throw ValidationException::withMessages(['mapping' => 'This mapping was retired.']);
            }

            $indicator = $mapping->indicator;
            $this->melSubmissions->assertCanReport($indicator, $period, $unit);

            $result = $this->compute($mapping, $period, $unit);
            if ($result['used'] === 0) {
                throw ValidationException::withMessages(['run' => 'No accepted submission counts for this period and unit, so there is nothing to send.']);
            }

            $open = $this->melSubmissions->open($indicator, $period, $unit, lock: true);
            if ($open !== null && $open->source !== 'collect') {
                throw ValidationException::withMessages(['run' => 'A result for this period and unit is already being entered by hand. Finish or discard it in Data entry first.']);
            }
            if ($open !== null && ! $open->isEditable()) {
                throw ValidationException::withMessages(['run' => 'The result for this period and unit is waiting for verification. Wait for the decision, or ask the verifier to send it back.']);
            }

            // Earlier runs for this indicator, period and unit stop counting.
            $earlier = CalculationRun::query()
                ->where('indicator_id', $indicator->id)
                ->where('reporting_period_id', $period->id)
                ->where('programme_unit_id', $unit->id)
                ->where('status', CalculationRun::SENT)
                ->lockForUpdate()
                ->pluck('id');
            CalculationRow::query()->whereIn('run_id', $earlier)->update(['standing' => false]);
            CalculationRun::query()->whereKey($earlier)->update(['status' => CalculationRun::SUPERSEDED]);

            $run = CalculationRun::query()->create([
                'mapping_id' => $mapping->id,
                'indicator_id' => $indicator->id,
                'reporting_period_id' => $period->id,
                'programme_unit_id' => $unit->id,
                'value' => $result['value'],
                'numerator' => $result['numerator'],
                'denominator' => $result['denominator'],
                'considered' => $result['considered'],
                'accepted' => $result['accepted'],
                'used' => $result['used'],
                'excluded' => $result['excluded'],
                'status' => CalculationRun::SENT,
                'created_by' => $user->id,
            ]);
            foreach ($result['rows'] as $row) {
                CalculationRow::query()->create([
                    'run_id' => $run->id,
                    'indicator_id' => $indicator->id,
                    'submission_id' => $row['submission']->id,
                    'revision_id' => $row['revision_id'],
                    'amount' => $row['amount'],
                    'in_numerator' => $row['in_numerator'],
                    'standing' => true,
                ]);
            }

            $figure = $result['value'] !== null
                ? ['value' => $result['value']]
                : ['numerator' => $result['numerator'], 'denominator' => $result['denominator']];
            $melSubmission = $this->melSubmissions->save($indicator, $period, $unit, [
                ...$figure,
                'narrative' => "Calculated from {$result['used']} accepted ".($result['used'] === 1 ? 'submission' : 'submissions')
                    ." of \"{$mapping->form->name}\" (calculation ".substr($run->id, 0, 8).').',
            ], $user, ['source' => 'collect', 'source_reference' => $run->id]);
            // An earlier run's draft now points to this run.
            if ($melSubmission->source_reference !== $run->id) {
                $melSubmission->forceFill(['source_reference' => $run->id])->save();
            }

            $run->forceFill(['mel_submission_id' => $melSubmission->id])->save();
            $this->audit->record('calculation.sent', $run, $user, [
                'used' => $run->used,
                'value' => $run->value ?? $run->numerator.' / '.$run->denominator,
                'replaced' => $earlier->count() > 0 ? $earlier->count() : null,
            ]);

            return $run;
        });
    }

    /**
     * A counted submission changed after its run was sent: the run is
     * flagged so someone recalculates. Its sent value is left as it was.
     */
    public function sourceChanged(Submission $submission, string $what): void
    {
        $runs = CalculationRow::query()
            ->where('submission_id', $submission->id)
            ->where('standing', true)
            ->pluck('run_id');

        if ($runs->isEmpty()) {
            return;
        }

        CalculationRun::query()
            ->whereKey($runs)
            ->where('status', CalculationRun::SENT)
            ->update([
                'flagged_at' => now(),
                'flag_reason' => mb_substr("A submission it counted was {$what} (".substr($submission->id, 0, 8).'). Recalculate to update the result.', 0, 255),
            ]);
    }

    /**
     * The M&E result a run made, if it still stands for the period and unit.
     */
    public static function result(CalculationRun $run): ?MelSubmission
    {
        return $run->mel_submission_id === null ? null : MelSubmission::query()->find($run->mel_submission_id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{calculation: string, question: string|null, condition: array<string, mixed>|null, numerator_condition: array<string, mixed>|null, distinct_by: string|null, note: string|null}
     */
    private function validated(Form $form, Indicator $indicator, array $data, ?IndicatorMapping $replacing): array
    {
        $errors = [];
        $version = $form->currentVersion;
        $questions = $version === null ? [] : Definition::questions($version->definition);
        $calculation = is_string($data['calculation'] ?? null) ? $data['calculation'] : '';
        $measure = $indicator->currentVersion->measure ?? null;

        if ($version === null || $form->status === Form::ARCHIVED) {
            $errors['form'] = 'Only a published form that is not archived can feed an indicator.';
        }
        if ($form->programme_id === null || $form->programme_id !== $indicator->programme_id) {
            $errors['indicator_id'] = 'Choose an indicator of the form\'s programme.';
        } elseif ($indicator->status === Indicator::RETIRED) {
            $errors['indicator_id'] = "{$indicator->code} is retired.";
        }

        if (! in_array($calculation, IndicatorMapping::CALCULATIONS, true)) {
            $errors['calculation'] = 'Choose how the value is calculated.';
        } elseif (! in_array($measure, IndicatorMapping::MEASURES[$calculation], true)) {
            $errors['calculation'] = 'A '.str_replace('_', ' ', (string) $measure).' indicator cannot be calculated as '.self::calculationLabel($calculation).'.';
        }

        $question = self::name($data['question'] ?? null);
        if (in_array($calculation, [IndicatorMapping::SUM, IndicatorMapping::AVERAGE], true)) {
            if ($question === null || ! in_array($questions[$question]['type'] ?? null, self::NUMERIC, true)) {
                $errors['question'] = 'Choose a number question of the form.';
            }
        } else {
            $question = null;
        }

        $distinctBy = self::name($data['distinct_by'] ?? null);
        if ($distinctBy !== null && ! in_array($questions[$distinctBy]['type'] ?? null, self::IDENTIFIERS, true)) {
            $errors['distinct_by'] = 'Choose a text, whole number or single choice question that identifies the household or person.';
        }

        $condition = self::emptyToNull($data['condition'] ?? null);
        if ($condition !== null && ($message = $this->checkCondition($condition, $questions)) !== null) {
            $errors['condition'] = $message;
        }

        $numeratorCondition = self::emptyToNull($data['numerator_condition'] ?? null);
        if ($calculation === IndicatorMapping::PERCENTAGE) {
            if ($numeratorCondition === null) {
                $errors['numerator_condition'] = 'Say which submissions count in the percentage.';
            } elseif (($message = $this->checkCondition($numeratorCondition, $questions)) !== null) {
                $errors['numerator_condition'] = $message;
            }
        } else {
            $numeratorCondition = null;
        }

        $duplicate = IndicatorMapping::query()
            ->where('form_id', $form->id)
            ->where('indicator_id', $indicator->id)
            ->where('status', IndicatorMapping::ACTIVE)
            ->when($replacing !== null, fn ($query) => $query->whereKeyNot($replacing->id))
            ->exists();
        if ($duplicate) {
            $errors['indicator_id'] = "This form already feeds {$indicator->code}. Change that mapping instead.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $note = is_string($data['note'] ?? null) ? trim($data['note']) : '';

        return [
            'calculation' => $calculation,
            'question' => $question,
            'condition' => $condition,
            'numerator_condition' => $numeratorCondition,
            'distinct_by' => $distinctBy,
            'note' => $note === '' ? null : mb_substr($note, 0, 2000),
        ];
    }

    /**
     * Checks a condition tree against the form's current questions: known
     * questions, known operators, and comparisons that suit the type.
     *
     * @param  array<string, mixed>  $condition
     * @param  array<string, array<string, mixed>>  $questions
     */
    private function checkCondition(array $condition, array $questions, int $depth = 0): ?string
    {
        if ($depth > 8) {
            return 'The condition is nested too deeply.';
        }

        foreach (['all', 'any'] as $join) {
            if (array_key_exists($join, $condition)) {
                if (! is_array($condition[$join]) || $condition[$join] === []) {
                    return 'Add at least one condition.';
                }
                foreach ($condition[$join] as $part) {
                    if (! is_array($part) || ($message = $this->checkCondition($part, $questions, $depth + 1)) !== null) {
                        return $message ?? 'A condition is incomplete.';
                    }
                }

                return null;
            }
        }
        if (array_key_exists('not', $condition)) {
            return is_array($condition['not']) ? $this->checkCondition($condition['not'], $questions, $depth + 1) : 'A condition is incomplete.';
        }

        $name = $condition['q'] ?? null;
        $op = $condition['op'] ?? null;
        if (! is_string($name) || ! isset($questions[$name])) {
            return 'Choose a question of the form in each condition.';
        }
        if (! in_array($op, self::OPERATORS, true)) {
            return 'Choose how the answer is compared.';
        }

        $type = $questions[$name]['type'] ?? null;
        $label = $questions[$name]['label'] ?? $name;
        if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true) && ! in_array($type, ['integer', 'decimal', 'date', 'time', 'datetime'], true)) {
            return "\"{$label}\" is not a number or a date, so it cannot be compared as larger or smaller.";
        }
        if ($op === 'includes' && $type !== 'select_multiple') {
            return "\"{$label}\" allows one answer only: use \"is\" instead of \"includes\".";
        }
        if (! in_array($op, ['answered', 'not_answered'], true) && ! array_key_exists('value', $condition)) {
            return "Give the answer \"{$label}\" is compared with.";
        }

        return null;
    }

    private function retireRow(IndicatorMapping $mapping, User $user): void
    {
        $mapping->forceFill(['status' => IndicatorMapping::RETIRED, 'retired_by' => $user->id, 'retired_at' => now()])->save();
    }

    public static function calculationLabel(string $calculation): string
    {
        return match ($calculation) {
            IndicatorMapping::COUNT => 'a count of submissions',
            IndicatorMapping::SUM => 'a sum of a number question',
            IndicatorMapping::AVERAGE => 'an average of a number question',
            IndicatorMapping::PERCENTAGE => 'a percentage of submissions',
            default => $calculation,
        };
    }

    private static function name(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $value) === 1 ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function emptyToNull(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }
}
