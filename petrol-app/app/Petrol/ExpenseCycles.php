<?php

namespace App\Petrol;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSchedule;
use App\Models\User;
use App\Support\BusinessDate;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Turns a due schedule into an unpaid bank expense. A schedule is not an
 * expense. Each due date is posted once. A missed run catches up one date
 * at a time instead of combining them.
 */
class ExpenseCycles
{
    public function __construct(
        private readonly Cashbook $cashbook,
        private readonly Rates $rates,
    ) {}

    public function generateDue(?User $actor = null, ?string $branchId = null): int
    {
        $today = BusinessDate::today();
        $query = ExpenseSchedule::query()->where('active', true)->whereDate('next_due_on', '<=', $today);
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $made = 0;
        foreach ($query->orderBy('next_due_on')->get() as $schedule) {
            if ($schedule->status === ExpenseSchedule::PAUSED || $schedule->status === ExpenseSchedule::ENDED) {
                continue;
            }
            $user = $actor ?? User::query()->find($schedule->created_by);
            if (! $user instanceof User) {
                continue;
            }
            $made += $this->catchUp($schedule, $user, $today);
        }

        return $made;
    }

    public function pause(ExpenseSchedule $schedule): void
    {
        if ($schedule->status === ExpenseSchedule::ENDED) {
            throw ValidationException::withMessages(['schedule' => 'This schedule has ended.']);
        }
        $schedule->forceFill(['status' => ExpenseSchedule::PAUSED, 'active' => false])->save();
    }

    public function resume(ExpenseSchedule $schedule): void
    {
        if ($schedule->status === ExpenseSchedule::ENDED) {
            throw ValidationException::withMessages(['schedule' => 'This schedule has ended.']);
        }
        $schedule->forceFill(['status' => ExpenseSchedule::ACTIVE, 'active' => true])->save();
    }

    public function end(ExpenseSchedule $schedule): void
    {
        $schedule->forceFill([
            'status' => ExpenseSchedule::ENDED,
            'active' => false,
            'ends_on' => BusinessDate::today(),
        ])->save();
    }

    private function catchUp(ExpenseSchedule $schedule, User $user, string $today): int
    {
        $made = 0;
        for ($step = 0; $step < 36; $step++) {
            $schedule->refresh();
            if (! $schedule->active || $schedule->next_due_on->toDateString() > $today) {
                break;
            }
            if ($schedule->ends_on !== null && $schedule->next_due_on->toDateString() > $schedule->ends_on->toDateString()) {
                $schedule->forceFill(['active' => false, 'status' => ExpenseSchedule::ENDED])->save();
                break;
            }

            $category = ExpenseCategory::query()->whereKey($schedule->category_id)->first();
            if ($category === null) {
                break;
            }

            $dueOn = $schedule->next_due_on->toDateString();
            $snapshot = $this->rates->snapshot($schedule->branch_id, $schedule->currency, $schedule->amount_minor);
            $this->cashbook->expense(
                $schedule->branch_id,
                null,
                $category->name,
                $schedule->description,
                $schedule->amount_minor,
                Expense::BANK,
                $user,
                'schedule:'.$schedule->id.':'.$dueOn,
                [
                    'category_id' => $category->id,
                    'schedule_id' => $schedule->id,
                    'currency' => $snapshot['currency'],
                    'fx_base' => $snapshot['fx_base'],
                    'fx_quote' => $snapshot['fx_quote'],
                    'fx_rate_minor' => $snapshot['fx_rate_minor'],
                    'reporting_amount_minor' => $snapshot['reporting_minor'],
                    'payee' => $schedule->payee,
                ],
            );

            $next = $this->advance($schedule->next_due_on, $schedule->frequency);
            $ended = $schedule->ends_on !== null && $next->toDateString() > $schedule->ends_on->toDateString();
            $schedule->forceFill([
                'next_due_on' => $next->toDateString(),
                'active' => ! $ended,
                'status' => $ended ? ExpenseSchedule::ENDED : ExpenseSchedule::ACTIVE,
            ])->save();
            $made++;
        }

        return $made;
    }

    private function advance(CarbonInterface $due, string $frequency): CarbonInterface
    {
        return match ($frequency) {
            ExpenseSchedule::DAILY => $due->addDay(),
            ExpenseSchedule::WEEKLY => $due->addWeek(),
            ExpenseSchedule::QUARTERLY => $due->addMonths(3),
            ExpenseSchedule::YEARLY => $due->addYear(),
            default => $due->addMonth(),
        };
    }
}
