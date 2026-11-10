<?php

namespace App\Actions;

use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Support\Money as Amounts;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Money already spent that is a project's spending: from petty cash, or from
 * a staff advance. It needs an approved budget with enough left in the
 * category, as any approval does, and shows on the project like any other.
 * Whoever charges it tells Finance, for the money it came from.
 */
final class ProjectCharge
{
    /**
     * @param  array{description: string, payee: string|null, amount_minor: int, spent_on: CarbonImmutable, currency: string, reference: string, receipt: array{name: string, path: string, mime: string, size: int}}  $details
     * @param  callable(string): mixed  $linked  told the expense's id
     *
     * @throws ValidationException on $amountField and project_id
     */
    public function charge(Project $project, CostCategory $category, array $details, ?User $by, callable $linked, string $amountField = 'amount'): Expense
    {
        if (! $project->isOpen()) {
            throw ValidationException::withMessages(['project_id' => "{$project->code} is closed."]);
        }
        if ($category === CostCategory::Labour) {
            throw ValidationException::withMessages(['category' => 'Labour comes from time logged.']);
        }
        if ($project->currency !== $details['currency']) {
            throw ValidationException::withMessages(['project_id' => "{$project->code} is in {$project->currency}, and this is in {$details['currency']}."]);
        }

        Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        $available = Spending::categoryAvailable($project, $category);
        if ($available === null) {
            throw ValidationException::withMessages(['project_id' => "{$project->code} has no approved budget."]);
        }
        if ($details['amount_minor'] > $available) {
            throw ValidationException::withMessages([$amountField => "{$category->label()} on {$project->code} has ".Amounts::format(max(0, $available), $project->currency).' left.']);
        }

        $expense = $project->expenses()->create([
            'category' => $category,
            'description' => $details['description'],
            'supplier' => $details['payee'],
            'amount_minor' => $details['amount_minor'],
            'status' => ExpenseStatus::Paid,
            'direct' => true,
            'spent_on' => $details['spent_on']->toDateString(),
            'recorded_by' => $by?->id,
            'paid_by' => $by?->id,
            'payment_reference' => $details['reference'],
        ]);
        $expense->documents()->create([
            'original_name' => $details['receipt']['name'],
            'path' => $details['receipt']['path'],
            'mime_type' => $details['receipt']['mime'],
            'size' => $details['receipt']['size'],
            'uploaded_by' => $by?->id,
        ]);
        $linked($expense->id);

        return $expense;
    }
}
