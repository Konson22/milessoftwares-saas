<?php

namespace App\Mel;

use App\Models\Indicator;
use App\Models\IndicatorBaseline;
use App\Models\IndicatorTarget;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Baselines and targets. Neither is ever edited: setting one replaces the
 * live row by a new one that supersedes it, so every earlier figure stays
 * on record with who changed it and why.
 *
 * Both are given in the indicator's own terms (a percentage as 45, a rate
 * per 1,000 as 2.5). A baseline of a fraction may also keep the numerator
 * and denominator it was measured from.
 */
class Targets
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Programmes $programmes,
    ) {}

    /**
     * @param  array{value: mixed, numerator?: mixed, denominator?: mixed, measured_on: string, source?: string|null, notes?: string|null, change_reason?: string|null}  $data
     */
    public function setBaseline(Indicator $indicator, ?string $unitId, array $data, User $user): IndicatorBaseline
    {
        $this->programmes->assertNotArchived($indicator->programme);
        $this->assertUnit($indicator, $unitId);
        $version = $indicator->currentVersion ?? throw ValidationException::withMessages(['value' => 'The indicator has no definition.']);

        $value = $this->value($data['value'], 'value');
        $parts = $version->isFraction()
            ? Figures::from($version, $data, required: false)
            : ['numerator' => null, 'denominator' => null];

        return DB::transaction(function () use ($indicator, $unitId, $data, $value, $parts, $user) {
            $live = IndicatorBaseline::query()->where('indicator_id', $indicator->id)->where('unit_id', $unitId)->whereNull('superseded_at')->lockForUpdate()->first();
            $reason = $this->reason($live !== null, $data['change_reason'] ?? null);
            $live?->update(['superseded_at' => now()]);

            $baseline = IndicatorBaseline::query()->create([
                'indicator_id' => $indicator->id,
                'unit_id' => $unitId,
                'value' => $value,
                'numerator' => $parts['numerator'],
                'denominator' => $parts['denominator'],
                'measured_on' => $data['measured_on'],
                'source' => $data['source'] ?? null,
                'notes' => $data['notes'] ?? null,
                'supersedes_id' => $live?->id,
                'change_reason' => $reason,
                'created_by' => $user->id,
            ]);

            $this->audit->record($live === null ? 'baseline.set' : 'baseline.replaced', $indicator, $user, [
                'unit' => $baseline->unit?->code,
                'from' => $live?->value === null ? null : Numbers::trim($live->value),
                'to' => Numbers::trim($value),
                'reason' => $reason,
            ]);

            return $baseline;
        });
    }

    /**
     * Sets (or, with no value, removes) the target of one period. A
     * "maintain" indicator takes a range (min_value / max_value).
     *
     * @param  array{value?: mixed, min_value?: mixed, max_value?: mixed, notes?: string|null, change_reason?: string|null}  $data
     */
    public function setTarget(Indicator $indicator, ReportingPeriod $period, ?string $unitId, array $data, User $user): ?IndicatorTarget
    {
        $this->programmes->assertNotArchived($indicator->programme);
        $this->assertUnit($indicator, $unitId);
        $version = $indicator->currentVersion ?? throw ValidationException::withMessages(['value' => 'The indicator has no definition.']);

        if ($period->programme_id !== $indicator->programme_id) {
            throw ValidationException::withMessages(['reporting_period_id' => 'Choose a period of this programme.']);
        }

        $figures = $version->direction === 'maintain'
            ? ['value' => null, 'min_value' => $this->value($data['min_value'] ?? null, 'min_value', false), 'max_value' => $this->value($data['max_value'] ?? null, 'max_value', false)]
            : ['value' => $this->value($data['value'] ?? null, 'value', false), 'min_value' => null, 'max_value' => null];

        if ($figures['min_value'] !== null && $figures['max_value'] !== null && Numbers::compare($figures['min_value'], $figures['max_value']) > 0) {
            throw ValidationException::withMessages(['max_value' => 'The top of the range is below its bottom.']);
        }

        $removing = $figures['value'] === null && $figures['min_value'] === null && $figures['max_value'] === null;

        return DB::transaction(function () use ($indicator, $period, $unitId, $data, $figures, $removing, $user) {
            $live = IndicatorTarget::query()
                ->where('indicator_id', $indicator->id)
                ->where('reporting_period_id', $period->id)
                ->where('unit_id', $unitId)
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->first();

            if ($live === null && $removing) {
                return null;
            }

            $reason = $this->reason($live !== null, $data['change_reason'] ?? null);
            $live?->update(['superseded_at' => now()]);

            $target = $removing ? null : IndicatorTarget::query()->create([
                ...$figures,
                'indicator_id' => $indicator->id,
                'reporting_period_id' => $period->id,
                'unit_id' => $unitId,
                'notes' => $data['notes'] ?? null,
                'supersedes_id' => $live?->id,
                'change_reason' => $reason,
                'created_by' => $user->id,
            ]);

            $describe = fn (?string $value, ?string $min, ?string $max) => $value !== null
                ? Numbers::trim($value)
                : ($min === null && $max === null ? null : ($min === null ? '' : Numbers::trim($min)).'–'.($max === null ? '' : Numbers::trim($max)));

            $this->audit->record(match (true) {
                $removing => 'target.removed',
                $live === null => 'target.set',
                default => 'target.replaced',
            }, $indicator, $user, [
                'period' => $period->label,
                'unit' => $unitId === null ? null : $indicator->programme->units()->find($unitId)?->code,
                'from' => $live === null ? null : $describe($live->value, $live->min_value, $live->max_value),
                'to' => $describe($figures['value'], $figures['min_value'], $figures['max_value']),
                'reason' => $reason,
            ]);

            return $target;
        });
    }

    private function value(mixed $input, string $key, bool $required = true): ?string
    {
        $value = Numbers::parse($input);

        if ($value === null && ($required || ($input !== null && $input !== ''))) {
            throw ValidationException::withMessages([$key => $input === null || $input === '' ? 'Enter a value.' : 'Enter a number.']);
        }

        return $value;
    }

    private function reason(bool $replacing, ?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        if ($replacing && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages(['change_reason' => 'Say why it changes: the earlier figure stays on record.']);
        }

        return $reason === '' ? null : $reason;
    }

    private function assertUnit(Indicator $indicator, ?string $unitId): void
    {
        if ($unitId !== null && ! $indicator->programme->units()->whereKey($unitId)->exists()) {
            throw ValidationException::withMessages(['unit_id' => 'Choose a unit of this programme.']);
        }
    }
}
