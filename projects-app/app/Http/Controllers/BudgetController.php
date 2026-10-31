<?php

namespace App\Http\Controllers;

use App\Enums\CostCategory;
use App\Http\Controllers\Concerns\ShowsProject;
use App\Models\BudgetLine;
use App\Models\Expense;
use App\Models\Project;
use App\Support\ActivityLog;
use App\Support\Money;
use App\Support\ProjectMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Budget against actual: the budget by category, against time at cost
 * (labour) and expenses (everything else).
 */
class BudgetController extends Controller
{
    use ShowsProject;

    public function show(Request $request, Project $project): Response
    {
        $metrics = ProjectMetrics::for($project);
        $lines = $project->budgetLines()->orderBy('category')->get();
        $expenses = $project->expenses()->with('recorder:id,name')->latest('spent_on')->get();

        $planned = $lines->groupBy(fn (BudgetLine $line) => $line->category->value)->map->sum('amount_minor');
        $spent = $expenses->groupBy(fn (Expense $expense) => $expense->category->value)->map->sum('amount_minor');
        $spent[CostCategory::Labour->value] = $metrics['labourMinor'];

        return Inertia::render('projects/budget', [
            'project' => $this->projectHeader($request, $project),
            'budgetMinor' => $project->budget_minor,
            'actualMinor' => $metrics['actualMinor'],
            'byCategory' => collect(CostCategory::cases())->map(fn (CostCategory $category) => [
                'category' => $category->value,
                'label' => $category->label(),
                'budgetMinor' => (int) ($planned[$category->value] ?? 0),
                'actualMinor' => (int) ($spent[$category->value] ?? 0),
            ])->filter(fn (array $row) => $row['budgetMinor'] > 0 || $row['actualMinor'] > 0)->values(),
            'lines' => $lines->map(fn (BudgetLine $line) => [
                'id' => $line->id,
                'category' => $line->category->label(),
                'description' => $line->description,
                'amountMinor' => $line->amount_minor,
            ]),
            'expenses' => $expenses->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'category' => $expense->category->label(),
                'description' => $expense->description,
                'supplier' => $expense->supplier,
                'amountMinor' => $expense->amount_minor,
                'spentOn' => $expense->spent_on->toDateString(),
                'recordedBy' => $expense->recorder->name,
            ]),
            'categories' => CostCategory::options(),
            'expenseCategories' => CostCategory::options(withLabour: false),
            'today' => today()->toDateString(),
        ]);
    }

    /**
     * A line of the budget; the project's budget becomes the lines' sum.
     */
    public function addLine(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(CostCategory::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'string', Money::rule($project->currency)],
        ]);

        $project->budgetLines()->create([
            'category' => $validated['category'],
            'description' => $this->text($validated['description'] ?? null),
            'amount_minor' => Money::toMinor($validated['amount'], $project->currency),
        ]);
        $this->resetBudget($project);
        ActivityLog::record($project, $this->member($request), 'Budget now '.Money::format($project->budget_minor, $project->currency));

        return back()->with('success', 'Budget line added.');
    }

    public function removeLine(Request $request, Project $project, BudgetLine $budgetLine): RedirectResponse
    {
        abort_unless($budgetLine->project_id === $project->id, 404);

        $budgetLine->delete();
        $this->resetBudget($project);
        ActivityLog::record($project, $this->member($request), 'Budget now '.Money::format($project->budget_minor, $project->currency));

        return back()->with('success', 'Budget line removed.');
    }

    public function addExpense(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(CostCategory::class), Rule::notIn([CostCategory::Labour->value])],
            'description' => ['required', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'string', Money::rule($project->currency)],
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
        ], ['category.not_in' => 'Labour comes from time logged.']);

        $expense = $project->expenses()->create([
            'category' => $validated['category'],
            'description' => trim($validated['description']),
            'supplier' => $this->text($validated['supplier'] ?? null),
            'amount_minor' => Money::toMinor($validated['amount'], $project->currency),
            'spent_on' => $validated['spent_on'],
            'recorded_by' => $this->member($request)->id,
        ]);
        ActivityLog::record($project, $this->member($request), "Recorded an expense: {$expense->description}, ".Money::format($expense->amount_minor, $project->currency));

        return back()->with('success', 'Expense recorded.');
    }

    public function removeExpense(Request $request, Project $project, Expense $expense): RedirectResponse
    {
        abort_unless($expense->project_id === $project->id, 404);

        $expense->delete();
        ActivityLog::record($project, $this->member($request), "Removed the expense {$expense->description}");

        return back()->with('success', 'Expense removed.');
    }

    private function resetBudget(Project $project): void
    {
        $project->forceFill(['budget_minor' => (int) $project->budgetLines()->sum('amount_minor')])->save();
    }
}
