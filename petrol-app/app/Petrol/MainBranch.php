<?php

namespace App\Petrol;

use App\Models\Station;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exactly one station in a company is the Main Branch. The branch row
 * stays in Miles Central. This flag does not grant access, and it does
 * not move tanks, shifts, or sales off the branch that recorded them.
 */
class MainBranch
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * The first active station of a company becomes Main. A second save
     * that loses the race keeps the station and leaves the existing Main.
     */
    public function claimIfNone(Station $station): Station
    {
        if (! $station->active || $station->is_main) {
            return $station;
        }

        $station->is_main = ! Station::query()
            ->where('is_main', true)
            ->whereKeyNot($station->id)
            ->lockForUpdate()
            ->exists();

        if (! $station->is_main) {
            return $station;
        }

        try {
            DB::transaction(fn () => $station->save());
        } catch (UniqueConstraintViolationException) {
            $station->is_main = false;
            $station->save();
        }

        return $station;
    }

    public function designate(string $branchId, User $by): Station
    {
        return DB::transaction(function () use ($branchId, $by) {
            $stations = Station::query()->orderBy('id')->lockForUpdate()->get();
            $target = $stations->firstWhere('branch_id', $branchId);

            if ($target === null || ! $target->active) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Set up that station, and keep it open, before making it the main branch.',
                ]);
            }

            $current = $stations->firstWhere('is_main', true);
            if ($current?->is($target)) {
                return $target;
            }

            if ($current !== null) {
                $current->is_main = false;
                $current->save();
            }

            $target->is_main = true;
            $target->save();

            $this->audit->record($by, 'station.main_changed', $target, [
                'branch_id' => $current?->branch_id,
            ], [
                'branch_id' => $target->branch_id,
            ], $target->branch_id);

            return $target;
        });
    }
}
