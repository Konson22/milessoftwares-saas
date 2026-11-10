<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Enums\BudgetVersionStatus;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules of versioned budgets, in one place:
 * - A project has at most one working version (draft or submitted) besides
 *   its approved one. Only a draft's lines change; adding a line with no
 *   draft starts one (a revision copies the approved lines).
 * - A version is submitted, then approved by someone other than whoever
 *   submitted it, or returned to draft with a reason. Approving supersedes
 *   the previous approved version; approved versions never change.
 * - A revision cannot take a category below what is already committed or
 *   spent in it.
 * - The project's budget is the approved total, or until there is one the
 *   working version's total.
 * Every change runs in a transaction on the locked project and is audited.
 */
final class Budgets
{
    public static function approved(Project $project): ?BudgetVersion
    {
        return $project->budgetVersions()->where('status', BudgetVersionStatus::Approved)->first();
    }

    public static function working(Project $project): ?BudgetVersion
    {
        return $project->budgetVersions()
            ->whereIn('status', [BudgetVersionStatus::Draft, BudgetVersionStatus::Submitted])
            ->first();
    }

    /**
     * The draft to edit: the working one, or a new revision.
     *
     * @throws ValidationException
     */
    public static function revise(Project $project, User $by): BudgetVersion
    {
        return DB::transaction(function () use ($project, $by) {
            self::lock($project);
            $working = self::working($project);
            if ($working !== null) {
                if (! $working->isDraft()) {
                    throw ValidationException::withMessages(['budget' => "Version {$working->number} is awaiting approval: approve it or return it to draft first."]);
                }

                return $working;
            }

            $approved = self::approved($project);
            $version = $project->budgetVersions()->create([
                'number' => ((int) $project->budgetVersions()->max('number')) + 1,
                'status' => BudgetVersionStatus::Draft,
                'total_minor' => $approved->total_minor ?? 0,
                'created_by' => $by->id,
            ]);
            foreach ($approved->lines ?? [] as $line) {
                $version->lines()->create([
                    'project_id' => $project->id,
                    'category' => $line->category,
                    'description' => $line->description,
                    'amount_minor' => $line->amount_minor,
                ]);
            }
            Audit::record('budget.version_created', $version, ['project' => $project->code, 'number' => $version->number, 'from' => $approved?->number], actor: $by);

            return $version;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function addLine(Project $project, CostCategory $category, ?string $description, int $amountMinor, User $by): BudgetLine
    {
        return DB::transaction(function () use ($project, $category, $description, $amountMinor, $by) {
            $version = self::revise($project, $by);
            $line = $version->lines()->create([
                'project_id' => $project->id,
                'category' => $category,
                'description' => $description,
                'amount_minor' => $amountMinor,
            ]);
            self::retotal($version);
            Audit::record('budget.line_added', $version, ['category' => $category->value, 'amount_minor' => $amountMinor], actor: $by);
            ActivityLog::record($project, $by, "Budget version {$version->number}: added {$category->label()}, ".Money::format($amountMinor, $project->currency));

            return $line;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function removeLine(Project $project, BudgetLine $line, User $by): void
    {
        DB::transaction(function () use ($project, $line, $by) {
            self::lock($project);
            $version = BudgetVersion::query()->whereKey($line->budget_version_id)->lockForUpdate()->firstOrFail();
            if (! $version->isDraft()) {
                throw ValidationException::withMessages(['budget' => 'Only a draft version changes: start a revision.']);
            }

            $line->delete();
            self::retotal($version);
            Audit::record('budget.line_removed', $version, ['category' => $line->category->value, 'amount_minor' => $line->amount_minor], actor: $by);
            ActivityLog::record($project, $by, "Budget version {$version->number}: removed {$line->category->label()}, ".Money::format($line->amount_minor, $project->currency));
        });
    }

    /**
     * Throws away a draft revision (never an approved version).
     *
     * @throws ValidationException
     */
    public static function discard(Project $project, BudgetVersion $version, User $by): void
    {
        DB::transaction(function () use ($project, $version, $by) {
            self::lock($project);
            $version->refresh();
            if (! $version->isDraft()) {
                throw ValidationException::withMessages(['budget' => 'Only a draft version can be discarded.']);
            }

            $version->lines()->delete();
            $version->delete();
            self::resetProjectBudget($project);
            Audit::record('budget.version_discarded', $version, ['project' => $project->code, 'number' => $version->number], actor: $by);
            ActivityLog::record($project, $by, "Discarded budget version {$version->number}");
        });
    }

    /**
     * @throws ValidationException
     */
    public static function submit(Project $project, BudgetVersion $version, ?string $note, User $by): void
    {
        DB::transaction(function () use ($project, $version, $note, $by) {
            self::lock($project);
            $version->refresh();
            if (! $version->isDraft()) {
                throw ValidationException::withMessages(['budget' => 'Only a draft version is submitted.']);
            }
            if (! $version->lines()->exists()) {
                throw ValidationException::withMessages(['budget' => 'Add the budget lines before submitting.']);
            }

            $version->forceFill([
                'status' => BudgetVersionStatus::Submitted,
                'note' => $note,
                'submitted_by' => $by->id,
                'submitted_at' => now(),
                'returned_reason' => null,
            ])->save();
            Audit::record('budget.submitted', $version, ['project' => $project->code, 'number' => $version->number, 'total_minor' => $version->total_minor], actor: $by);
            Alerts::budgetSubmitted($project, $version, $by);
            ActivityLog::record($project, $by, "Submitted budget version {$version->number} for approval");
        });
    }

    /**
     * @throws ValidationException
     */
    public static function approve(Project $project, BudgetVersion $version, User $by): void
    {
        DB::transaction(function () use ($project, $version, $by) {
            self::lock($project);
            $version->refresh();
            if ($version->status !== BudgetVersionStatus::Submitted) {
                throw ValidationException::withMessages(['budget' => 'Only a submitted version is approved.']);
            }
            if ($version->submitted_by === $by->id) {
                throw ValidationException::withMessages(['budget' => 'Someone other than whoever submitted the budget approves it.']);
            }

            // What is already committed or spent stays covered.
            $planned = $version->lines()->get()->groupBy(fn (BudgetLine $line) => $line->category->value)->map->sum('amount_minor');
            $used = Expense::query()->where('project_id', $project->id)
                ->whereIn('status', [ExpenseStatus::Approved, ExpenseStatus::Paid])
                ->groupBy('category')->selectRaw('category, sum(amount_minor) as amount')
                ->pluck('amount', 'category');
            foreach ($used as $category => $amount) {
                $label = CostCategory::from((string) $category)->label();
                if ((int) $amount > (int) ($planned[$category] ?? 0)) {
                    throw ValidationException::withMessages(['budget' => "{$label}: ".Money::format((int) $amount, $project->currency).' is already committed or spent, more than this version gives it.']);
                }
            }

            $previous = self::approved($project);
            $previous?->forceFill(['status' => BudgetVersionStatus::Superseded])->save();
            $version->forceFill([
                'status' => BudgetVersionStatus::Approved,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();
            self::resetProjectBudget($project);
            Alerts::budgetDecided($project, $version, "{$by->name} approved the budget of {$project->code}", $by);
            Audit::record('budget.approved', $version, [
                'project' => $project->code,
                'number' => $version->number,
                'total_minor' => $version->total_minor,
                'supersedes' => $previous?->number,
            ], actor: $by);
            ActivityLog::record($project, $by, "Approved budget version {$version->number}: ".Money::format($version->total_minor, $project->currency));
        });
    }

    /**
     * @throws ValidationException
     */
    public static function returnToDraft(Project $project, BudgetVersion $version, string $reason, User $by): void
    {
        DB::transaction(function () use ($project, $version, $reason, $by) {
            self::lock($project);
            $version->refresh();
            if ($version->status !== BudgetVersionStatus::Submitted) {
                throw ValidationException::withMessages(['budget' => 'Only a submitted version is returned.']);
            }

            $version->forceFill(['status' => BudgetVersionStatus::Draft, 'returned_reason' => $reason])->save();
            Audit::record('budget.returned', $version, ['project' => $project->code, 'number' => $version->number, 'reason' => $reason], actor: $by);
            Alerts::budgetDecided($project, $version, "{$by->name} returned the budget of {$project->code}: {$reason}", $by);
            ActivityLog::record($project, $by, "Returned budget version {$version->number}: {$reason}");
        });
    }

    /**
     * Budget by category of the approved version (empty without one).
     *
     * @return array<string, int>
     */
    public static function approvedByCategory(Project $project): array
    {
        $approved = self::approved($project);
        if ($approved === null) {
            return [];
        }

        return $approved->lines()->groupBy('category')->orderBy('category')->selectRaw('category, sum(amount_minor) as amount')
            ->pluck('amount', 'category')->map(fn (mixed $amount) => (int) $amount)->all();
    }

    private static function retotal(BudgetVersion $version): void
    {
        $version->forceFill(['total_minor' => (int) $version->lines()->sum('amount_minor')])->save();
        self::resetProjectBudget($version->project);
    }

    private static function resetProjectBudget(Project $project): void
    {
        $version = self::approved($project) ?? self::working($project);
        $project->forceFill(['budget_minor' => $version->total_minor ?? 0])->save();
    }

    private static function lock(Project $project): void
    {
        Project::query()->whereKey($project->id)->lockForUpdate()->first();
    }
}
