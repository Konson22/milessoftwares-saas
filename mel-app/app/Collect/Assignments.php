<?php

namespace App\Collect;

use App\Collect\Models\Assignment;
use App\Collect\Models\Form;
use App\Mel\AuditTrail;
use App\Mel\Models\ProgrammeUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who collects with which form (ADR-0012 §7, §9). Nobody's phone gets a
 * form without an assignment, whatever their permissions.
 *
 * Ending an assignment is *closing* by default: no new forms, but forms
 * already filled in still arrive until closes_at (a week unless said
 * otherwise), so a collector who was offline loses nothing. Revoking
 * refuses everything at once; assigning the person again reopens it.
 */
class Assignments
{
    public const DEFAULT_CLOSING_DAYS = 7;

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Assigns people (or updates their live assignment) to collect with a form.
     *
     * @param  list<string>  $userIds
     * @param  int|null  $expected  submissions expected from each person (null: not set)
     * @return int how many were newly assigned
     */
    public function assign(Form $form, array $userIds, ?string $unitId, ?CarbonImmutable $startsOn, ?CarbonImmutable $endsOn, User $assigner, ?int $expected = null): int
    {
        if ($form->isArchived()) {
            throw ValidationException::withMessages(['form' => 'This form is archived.']);
        }
        if ($unitId !== null && ($form->programme_id === null || ! ProgrammeUnit::query()->whereKey($unitId)->where('programme_id', $form->programme_id)->exists())) {
            throw ValidationException::withMessages(['programme_unit_id' => 'Choose a unit of the form\'s programme.']);
        }
        if ($form->programme_unit_id !== null && $unitId !== null && $unitId !== $form->programme_unit_id) {
            throw ValidationException::withMessages(['programme_unit_id' => 'This form is for one unit only.']);
        }
        if ($startsOn !== null && $endsOn !== null && $endsOn->lessThan($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'The last day is before the first.']);
        }

        if ($expected !== null && ($expected < 1 || $expected > 100000)) {
            throw ValidationException::withMessages(['expected_submissions' => 'Expect between 1 and 100,000 submissions, or leave it empty.']);
        }

        return DB::transaction(function () use ($form, $userIds, $unitId, $startsOn, $endsOn, $assigner, $expected) {
            $created = 0;

            foreach (array_values(array_unique($userIds)) as $userId) {
                $assignment = Assignment::query()->live()->where('form_id', $form->id)->where('user_id', $userId)->lockForUpdate()->first();
                $values = [
                    'programme_unit_id' => $unitId ?? $form->programme_unit_id,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'status' => Assignment::ACTIVE,
                    'closes_at' => null,
                    'expected_submissions' => $expected,
                    // The project milestone the form serves, as it was when assigned.
                    'linked_milestone_id' => $form->linked_milestone_id,
                ];

                if ($assignment === null) {
                    $assignment = Assignment::query()->create([...$values, 'form_id' => $form->id, 'user_id' => $userId, 'assigned_by' => $assigner->id]);
                    $created++;
                    $this->audit->record('assignment.created', $assignment, $assigner, $this->details($assignment));
                } else {
                    $assignment->update([...$values, 'ended_by' => null, 'ended_at' => null]);
                    $this->audit->record('assignment.updated', $assignment, $assigner, $this->details($assignment));
                }
            }

            return $created;
        });
    }

    /**
     * No new forms from now on; forms already filled in are accepted until
     * $closesAt (a week from now when null).
     */
    public function close(Assignment $assignment, ?CarbonImmutable $closesAt, User $user): void
    {
        $closesAt ??= CarbonImmutable::now()->addDays(self::DEFAULT_CLOSING_DAYS);

        if ($closesAt->isPast()) {
            throw ValidationException::withMessages(['closes_at' => 'Choose a moment in the future, or revoke the assignment.']);
        }

        $this->end($assignment, Assignment::CLOSING, $closesAt, $user);
    }

    /**
     * Refuses everything from this person for this form, at once. What is
     * still on their phone stays there, read-only, until the assignment is
     * reopened.
     */
    public function revoke(Assignment $assignment, User $user): void
    {
        $this->end($assignment, Assignment::REVOKED, null, $user);
    }

    private function end(Assignment $assignment, string $status, ?CarbonImmutable $closesAt, User $user): void
    {
        DB::transaction(function () use ($assignment, $status, $closesAt, $user) {
            $locked = Assignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Assignment::REVOKED) {
                throw ValidationException::withMessages(['assignment' => 'This assignment was revoked already.']);
            }

            $locked->update(['status' => $status, 'closes_at' => $closesAt, 'ended_by' => $user->id, 'ended_at' => now()]);
            $assignment->setRawAttributes($locked->getAttributes(), true);
            $this->audit->record($status === Assignment::REVOKED ? 'assignment.revoked' : 'assignment.closed', $locked, $user, [
                'closes_at' => $closesAt?->toIso8601String(),
            ]);
        });
    }

    /**
     * @return array<string, string|null>
     */
    private function details(Assignment $assignment): array
    {
        return [
            'unit' => $assignment->unit?->code,
            'from' => $assignment->starts_on?->toDateString(),
            'to' => $assignment->ends_on?->toDateString(),
        ];
    }
}
