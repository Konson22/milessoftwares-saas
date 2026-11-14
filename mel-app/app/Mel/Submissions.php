<?php

namespace App\Mel;

use App\Models\DimensionCategory;
use App\Models\ImportBatch;
use App\Models\Indicator;
use App\Models\IndicatorBreakdown;
use App\Models\IndicatorTarget;
use App\Models\IndicatorVersion;
use App\Models\ProgrammeUnit;
use App\Models\ReportingPeriod;
use App\Models\ReviewDecision;
use App\Models\Submission;
use App\Models\SubmissionValue;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Entering results: one submission per indicator, period and unit, kept as
 * a draft until submitted. Correcting a verified value opens a new
 * revision beside it; the verified one stands until the revision is.
 */
class Submissions
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Programmes $programmes,
    ) {}

    /**
     * Saves the draft for this indicator, period and unit (creating it, or a
     * correction of a verified value). Values follow the indicator's
     * current definition.
     *
     * @param  array{value?: mixed, numerator?: mixed, denominator?: mixed, narrative?: string|null, deviation_note?: string|null, cells?: list<array<string, mixed>>}  $input
     * @param  array{source?: string, source_reference?: string|null, batch?: ImportBatch|null}  $origin
     */
    public function save(Indicator $indicator, ReportingPeriod $period, ProgrammeUnit $unit, array $input, User $user, array $origin = []): Submission
    {
        $this->assertCanReport($indicator, $period, $unit);
        $version = $indicator->currentVersion ?? throw ValidationException::withMessages(['value' => 'The indicator has no definition.']);
        $figure = Figures::from($version, $input, required: false);
        $cells = $this->cells($version, $input['cells'] ?? []);

        return DB::transaction(function () use ($indicator, $period, $unit, $input, $user, $origin, $version, $figure, $cells) {
            $open = $this->open($indicator, $period, $unit, lock: true);

            if ($open !== null && ! $open->isEditable()) {
                throw ValidationException::withMessages(['value' => 'This result has been submitted and is waiting for review.']);
            }

            $created = $open === null;
            $submission = $open ?? $this->start($indicator, $period, $unit, $user, $origin);
            $submission->fill([
                ...$figure,
                'indicator_version_id' => $version->id,
                'narrative' => $input['narrative'] ?? $submission->narrative,
                'deviation_note' => $input['deviation_note'] ?? $submission->deviation_note,
            ])->save();

            $submission->values()->delete();
            foreach ($cells as $cell) {
                SubmissionValue::query()->create([...$cell, 'submission_id' => $submission->id]);
            }

            if ($created) {
                $this->audit->record($submission->supersedes_id === null ? 'submission.started' : 'submission.correction_opened', $submission, $user, [
                    'source' => $submission->source === 'manual' ? null : $submission->source,
                ]);
            }

            return $submission;
        });
    }

    /**
     * Sends a draft (or a rejected result, corrected) for review.
     */
    public function submit(Submission $submission, User $user): void
    {
        $this->programmes->assertNotArchived($submission->programme);

        if (! $submission->isEditable()) {
            throw ValidationException::withMessages(['submission' => 'Only a draft or a result sent back can be submitted.']);
        }
        if ($submission->status === Submission::DRAFT && ! $submission->period->isOpen()) {
            throw ValidationException::withMessages(['submission' => "{$submission->period->label} is closed for new results."]);
        }

        $version = $submission->version;
        Figures::from($version, $submission->only(['value', 'numerator', 'denominator']));
        $this->reconcile($submission);
        $this->requireDeviationNote($submission);

        DB::transaction(function () use ($submission, $user) {
            $locked = Submission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['submission' => 'This result was submitted meanwhile.']);
            }

            $resubmitted = $locked->status === Submission::REJECTED;
            $submission->update(['status' => Submission::SUBMITTED, 'submitted_by' => $user->id, 'submitted_at' => now(), 'reviewer_id' => null]);
            $this->decide($submission, 'submitted', null, $user);
            $this->audit->record($resubmitted ? 'submission.resubmitted' : 'submission.submitted', $submission, $user);
        });
    }

    /**
     * Throws a draft away, with its evidence. Submitted results never are.
     */
    public function discard(Submission $submission, User $user): void
    {
        if ($submission->status !== Submission::DRAFT) {
            throw ValidationException::withMessages(['submission' => 'Only a draft that was never submitted can be discarded.']);
        }

        if ($submission->decisions()->exists()) {
            throw ValidationException::withMessages(['submission' => 'This draft has a review history and is kept.']);
        }

        $paths = $submission->evidence->pluck('path')->all();

        DB::transaction(function () use ($submission, $user) {
            $this->audit->record('submission.discarded', $submission, $user);
            $submission->evidence()->delete();
            $submission->values()->delete();
            $submission->delete();
        });

        DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
    }

    /**
     * The submission in progress for the key, if any.
     */
    public function open(Indicator $indicator, ReportingPeriod $period, ProgrammeUnit $unit, bool $lock = false): ?Submission
    {
        $query = Submission::query()
            ->where('indicator_id', $indicator->id)
            ->where('reporting_period_id', $period->id)
            ->where('unit_id', $unit->id)
            ->whereIn('status', Submission::OPEN);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function decide(Submission $submission, string $action, ?string $comment, User $user): ReviewDecision
    {
        return ReviewDecision::query()->create([
            'submission_id' => $submission->id,
            'action' => $action,
            'comment' => $comment,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
    }

    public function assertCanReport(Indicator $indicator, ReportingPeriod $period, ProgrammeUnit $unit): void
    {
        $this->programmes->assertNotArchived($indicator->programme);

        $error = match (true) {
            $period->programme_id !== $indicator->programme_id || $unit->programme_id !== $indicator->programme_id => 'That period or unit is not part of this programme.',
            $indicator->status !== Indicator::ACTIVE => "{$indicator->code} is not active: results are entered for active indicators only.",
            $period->frequency !== $indicator->frequency => "{$indicator->code} is reported {$indicator->frequency}, not in {$period->label}.",
            ! $period->isOpen() => "{$period->label} is closed for new results.",
            $unit->archived_at !== null => "{$unit->code} is archived.",
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['value' => $error]);
        }
    }

    /**
     * @param  array{source?: string, source_reference?: string|null, batch?: ImportBatch|null}  $origin
     */
    private function start(Indicator $indicator, ReportingPeriod $period, ProgrammeUnit $unit, User $user, array $origin): Submission
    {
        $standing = Submission::query()
            ->where('indicator_id', $indicator->id)
            ->where('reporting_period_id', $period->id)
            ->where('unit_id', $unit->id)
            ->whereIn('status', Submission::STANDING)
            ->lockForUpdate()
            ->first();

        return new Submission([
            'programme_id' => $indicator->programme_id,
            'indicator_id' => $indicator->id,
            'reporting_period_id' => $period->id,
            'unit_id' => $unit->id,
            'status' => Submission::DRAFT,
            'revision' => $standing === null ? 1 : $standing->revision + 1,
            'supersedes_id' => $standing?->id,
            'source' => $origin['source'] ?? 'manual',
            'source_reference' => $origin['source_reference'] ?? null,
            'import_batch_id' => $origin['batch']->id ?? null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Validates disaggregated cells against the version's breakdowns. Empty
     * cells are left out.
     *
     * @param  list<array<string, mixed>>  $input
     * @return list<array{breakdown_id: string, category_id: string, second_category_id: string|null, value: string|null, numerator: string|null, denominator: string|null}>
     */
    private function cells(IndicatorVersion $version, array $input): array
    {
        $breakdowns = $version->breakdowns->keyBy('id');
        $cells = [];
        $seen = [];

        foreach ($input as $i => $cell) {
            $breakdown = $breakdowns->get((string) ($cell['breakdown_id'] ?? ''));
            if (! $breakdown instanceof IndicatorBreakdown) {
                throw ValidationException::withMessages(["cells.{$i}" => 'This breakdown is not part of the indicator.']);
            }

            $figure = Figures::from($version, $cell, "cells.{$i}.", required: false);
            if ($figure['value'] === null && $figure['numerator'] === null) {
                continue;
            }

            $category = $this->category($breakdown->dimension_id, $cell['category_id'] ?? null);
            $second = $breakdown->second_dimension_id === null ? null : $this->category($breakdown->second_dimension_id, $cell['second_category_id'] ?? null);
            if ($category === null || ($breakdown->second_dimension_id !== null && $second === null)) {
                throw ValidationException::withMessages(["cells.{$i}" => 'Choose categories of the breakdown\'s dimensions.']);
            }

            $key = $breakdown->id.'|'.$category->id.'|'.($second->id ?? '');
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["cells.{$i}" => 'This cell is entered twice.']);
            }
            $seen[$key] = true;

            $cells[] = [
                'breakdown_id' => $breakdown->id,
                'category_id' => $category->id,
                'second_category_id' => $second?->id,
                ...$figure,
            ];
        }

        return $cells;
    }

    private function category(string $dimensionId, mixed $id): ?DimensionCategory
    {
        return is_string($id) ? DimensionCategory::query()->where('dimension_id', $dimensionId)->find($id) : null;
    }

    /**
     * Checks each breakdown's rule: every cell filled and adding up to the
     * total (must_equal_total), or adding up to no more than it
     * (at_most_total). Rules apply to counts and currency only.
     */
    private function reconcile(Submission $submission): void
    {
        $version = $submission->version;
        $total = $submission->value;

        foreach ($version->breakdowns as $breakdown) {
            if ($breakdown->rule === 'none' || $total === null || ! in_array($version->measure, ['count', 'currency'], true)) {
                continue;
            }

            $cells = array_values($submission->values()->where('breakdown_id', $breakdown->id)->get()
                ->map(fn (SubmissionValue $cell) => $cell->value)->filter(fn (?string $value) => $value !== null)->all());
            $expected = $breakdown->dimension->categories()->whereNull('archived_at')->count()
                * ($breakdown->secondDimension === null ? 1 : $breakdown->secondDimension->categories()->whereNull('archived_at')->count());
            $sum = Numbers::sum($cells);

            if ($breakdown->rule === 'must_equal_total' && (count($cells) < $expected || Numbers::compare($sum, $total) !== 0)) {
                throw ValidationException::withMessages(['cells' => "By {$breakdown->label()}: every cell must be filled and add up to the total (".Numbers::trim($sum).' of '.Numbers::trim($total).').']);
            }

            if ($breakdown->rule === 'at_most_total' && Numbers::compare($sum, $total) > 0) {
                throw ValidationException::withMessages(['cells' => "By {$breakdown->label()}: the cells add up to more than the total (".Numbers::trim($sum).' of '.Numbers::trim($total).').']);
            }
        }
    }

    /**
     * An off-track value is explained before it is submitted.
     */
    private function requireDeviationNote(Submission $submission): void
    {
        if (trim((string) $submission->deviation_note) !== '') {
            return;
        }

        $target = IndicatorTarget::query()
            ->where('indicator_id', $submission->indicator_id)
            ->where('reporting_period_id', $submission->reporting_period_id)
            ->where('unit_id', $submission->unit_id)
            ->whereNull('superseded_at')
            ->first();
        if ($target === null) {
            return;
        }

        $settings = Workspace::settings();
        $assessment = Performance::assess(
            $submission->version->direction,
            Calculator::valueOf($submission->version, $submission->only(['value', 'numerator', 'denominator'])),
            $target->value,
            min: $target->min_value,
            max: $target->max_value,
            onTrack: $settings->on_track_percent,
            attention: $settings->attention_percent,
        );

        if ($assessment['status'] === Performance::OFF_TRACK) {
            throw ValidationException::withMessages(['deviation_note' => 'This value is off track against its target. Explain the difference before submitting.']);
        }
    }
}
