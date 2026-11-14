<?php

namespace App\Mel;

use App\Models\Indicator;
use App\Models\IndicatorBreakdown;
use App\Models\IndicatorVersion;
use App\Models\Programme;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Indicators and their versioned definitions.
 *
 * Editing a definition nobody has reported under changes it in place.
 * Once a submission uses it, a change is a new version with a reason, and
 * every earlier value keeps the version it was reported under. The measure
 * (and a rate's multiplier) never changes once baselines, targets or
 * results exist: that would make old and new figures incomparable.
 */
class Indicators
{
    public const DEFINITION_FIELDS = [
        'name', 'definition', 'type', 'measure', 'unit_label', 'decimals', 'numerator_label', 'denominator_label',
        'rate_multiplier', 'calculation_method', 'direction', 'time_aggregation', 'unit_aggregation',
        'data_source', 'collection_method', 'means_of_verification',
    ];

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Programmes $programmes,
    ) {}

    /**
     * @param  array{result_id: string, code: string, frequency: string, responsible_id?: string|null, sort_order?: int}  $identity
     * @param  array<string, mixed>  $definition
     * @param  list<array{dimension_id: string, second_dimension_id?: string|null, rule: string}>  $breakdowns
     */
    public function create(Programme $programme, array $identity, array $definition, array $breakdowns, User $user): Indicator
    {
        $this->programmes->assertNotArchived($programme);
        $this->assertResult($programme, $identity['result_id']);
        self::assertDefinition($definition, $breakdowns);

        return DB::transaction(function () use ($programme, $identity, $definition, $breakdowns, $user) {
            $indicator = Indicator::query()->create([...$identity, 'programme_id' => $programme->id, 'status' => Indicator::DRAFT]);
            $version = $this->newVersion($indicator, 1, $definition, $breakdowns, null, $user);
            $indicator->update(['current_version_id' => $version->id]);
            $this->audit->record('indicator.created', $indicator, $user, ['name' => $version->name]);

            return $indicator;
        });
    }

    /**
     * @param  array{result_id: string, code: string, frequency: string, responsible_id?: string|null, sort_order?: int}  $identity
     */
    public function updateIdentity(Indicator $indicator, array $identity, User $user): void
    {
        $this->programmes->assertNotArchived($indicator->programme);
        $this->assertResult($indicator->programme, $identity['result_id']);

        if ($identity['frequency'] !== $indicator->frequency && Submission::query()->where('indicator_id', $indicator->id)->exists()) {
            throw ValidationException::withMessages(['frequency' => 'Results have been reported for this indicator; its reporting frequency stays.']);
        }

        DB::transaction(function () use ($indicator, $identity, $user) {
            $indicator->fill($identity);
            $changed = array_keys($indicator->getDirty());
            $indicator->save();
            $this->audit->record('indicator.updated', $indicator, $user, ['changed' => $changed ?: null]);
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<array{dimension_id: string, second_dimension_id?: string|null, rule: string}>  $breakdowns
     */
    public function revise(Indicator $indicator, array $definition, array $breakdowns, ?string $reason, User $user): IndicatorVersion
    {
        $this->programmes->assertNotArchived($indicator->programme);
        self::assertDefinition($definition, $breakdowns);

        return DB::transaction(function () use ($indicator, $definition, $breakdowns, $reason, $user) {
            $indicator = Indicator::query()->whereKey($indicator->id)->lockForUpdate()->firstOrFail();
            $current = $indicator->currentVersion ?? throw ValidationException::withMessages(['name' => 'This indicator has no definition.']);

            $measureChanges = $definition['measure'] !== $current->measure
                || ($definition['measure'] === 'rate' && (int) ($definition['rate_multiplier'] ?? 0) !== (int) $current->rate_multiplier);
            if ($measureChanges && $this->hasFigures($indicator)) {
                throw ValidationException::withMessages(['measure' => 'Baselines, targets or results exist in the current measure. Create a new indicator for a different measure.']);
            }

            $inUse = Submission::query()->where('indicator_version_id', $current->id)->exists();

            if (! $inUse) {
                $current->fill(collect($definition)->only(self::DEFINITION_FIELDS)->all())->save();
                $current->breakdowns()->delete();
                $this->createBreakdowns($current, $breakdowns);
                $this->audit->record('indicator.definition_edited', $indicator, $user, ['version' => $current->version]);

                return $current;
            }

            if ($reason === null || trim($reason) === '') {
                throw ValidationException::withMessages(['change_reason' => 'Results have been reported under this definition. Say why it changes; a new version is kept beside the old one.']);
            }

            $version = $this->newVersion($indicator, $current->version + 1, $definition, $breakdowns, $reason, $user);
            $indicator->update(['current_version_id' => $version->id]);
            $this->audit->record('indicator.revised', $indicator, $user, ['version' => $version->version, 'reason' => $reason]);

            return $version;
        });
    }

    public function setStatus(Indicator $indicator, string $status, User $user): void
    {
        $this->programmes->assertNotArchived($indicator->programme);

        DB::transaction(function () use ($indicator, $status, $user) {
            $from = $indicator->status;
            $indicator->update(['status' => $status]);
            $this->audit->record('indicator.status_changed', $indicator, $user, ['from' => $from, 'to' => $status]);
        });
    }

    /**
     * Checks the definition's rules against its measure.
     *
     * @param  array<string, mixed>  $definition
     * @param  list<array{dimension_id: string, second_dimension_id?: string|null, rule: string}>  $breakdowns
     */
    public static function assertDefinition(array $definition, array $breakdowns): void
    {
        $measure = (string) $definition['measure'];
        $errors = [];

        if (! in_array($definition['time_aggregation'], Measures::timeAggregations($measure), true)) {
            $errors['time_aggregation'] = 'Not possible for this measure.';
        }
        if (! in_array($definition['unit_aggregation'], Measures::unitAggregations($measure), true)) {
            $errors['unit_aggregation'] = 'Not possible for this measure.';
        }
        if ($measure === 'rate' && (int) ($definition['rate_multiplier'] ?? 0) < 1) {
            $errors['rate_multiplier'] = 'Say what the rate is per (1,000, 100,000...).';
        }
        if ($measure === 'yes_no' && $definition['direction'] === 'decrease') {
            $errors['direction'] = 'A yes/no indicator increases (yes is better) or is maintained.';
        }

        $seen = [];
        foreach ($breakdowns as $i => $breakdown) {
            $key = $breakdown['dimension_id'].'|'.($breakdown['second_dimension_id'] ?? '');
            if (($breakdown['second_dimension_id'] ?? null) === $breakdown['dimension_id']) {
                $errors["breakdowns.{$i}.second_dimension_id"] = 'Cross two different dimensions.';
            }
            if (isset($seen[$key])) {
                $errors["breakdowns.{$i}.dimension_id"] = 'This breakdown is listed twice.';
            }
            if (! in_array($breakdown['rule'], Measures::breakdownRules($measure), true)) {
                $errors["breakdowns.{$i}.rule"] = 'Cells of this measure do not add up to the total; choose "no rule".';
            }
            $seen[$key] = true;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function hasFigures(Indicator $indicator): bool
    {
        return Submission::query()->where('indicator_id', $indicator->id)->exists()
            || $indicator->baselines()->exists()
            || $indicator->targets()->exists();
    }

    private function assertResult(Programme $programme, string $resultId): void
    {
        if (! $programme->results()->whereKey($resultId)->exists()) {
            throw ValidationException::withMessages(['result_id' => 'Choose a result of this programme.']);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<array{dimension_id: string, second_dimension_id?: string|null, rule: string}>  $breakdowns
     */
    private function newVersion(Indicator $indicator, int $number, array $definition, array $breakdowns, ?string $reason, User $user): IndicatorVersion
    {
        $version = IndicatorVersion::query()->create([
            ...collect($definition)->only(self::DEFINITION_FIELDS)->all(),
            'indicator_id' => $indicator->id,
            'version' => $number,
            'change_reason' => $reason,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
        $this->createBreakdowns($version, $breakdowns);

        return $version;
    }

    /**
     * @param  list<array{dimension_id: string, second_dimension_id?: string|null, rule: string}>  $breakdowns
     */
    private function createBreakdowns(IndicatorVersion $version, array $breakdowns): void
    {
        foreach ($breakdowns as $breakdown) {
            IndicatorBreakdown::query()->create([
                'indicator_version_id' => $version->id,
                'dimension_id' => $breakdown['dimension_id'],
                'second_dimension_id' => $breakdown['second_dimension_id'] ?? null,
                'rule' => $breakdown['rule'],
            ]);
        }
    }
}
