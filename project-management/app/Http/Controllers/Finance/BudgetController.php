<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Budgets;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Http\Controllers\Concerns\ShowsProject;
use App\Http\Controllers\Controller;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Models\Expense;
use App\Models\GrantAllocation;
use App\Models\Project;
use App\Support\CsvExport;
use App\Support\Modules;
use App\Support\Money;
use App\Support\ProjectMetrics;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The budget: its versions (drafted, submitted, approved), and by category
 * the budget against what is committed (approved spending not yet paid)
 * and spent (paid spending, and labour: time at cost). The rules are in
 * App\Actions\Budgets.
 */
class BudgetController extends Controller
{
    use ShowsProject;

    /**
     * The lines of the approved version, or the working one until there is one.
     */
    public function export(Project $project): StreamedResponse
    {
        $version = Budgets::approved($project) ?? Budgets::working($project);
        $lines = $version === null ? collect() : $version->lines()->get();

        return CsvExport::download("{$project->code}-budget", [
            'Version' => 'version',
            'Status' => 'status',
            'Category' => 'category',
            'Description' => 'description',
            "Amount ({$project->currency})" => 'amountMinor',
        ], $lines->map(fn (BudgetLine $line) => [
            'version' => $version?->number,
            'status' => $version?->status->value,
            'category' => $line->category->label(),
            'description' => $line->description,
            'amountMinor' => $line->amount_minor,
        ]), $project->currency);
    }

    public function show(Request $request, Project $project): Response
    {
        $metrics = ProjectMetrics::for($project);
        $versions = $project->budgetVersions()->with('lines', 'submitter:id,name', 'approver:id,name')->get();
        $approved = $versions->first(fn (BudgetVersion $version) => $version->status->value === 'approved');
        $working = $versions->first(fn (BudgetVersion $version) => in_array($version->status->value, ['draft', 'submitted'], true));
        // Spending is checked against the approved version; until there is
        // one, the draft shows what is planned.
        $basis = $approved ?? $working;

        $planned = ($basis->lines ?? collect())->groupBy(fn (BudgetLine $line) => $line->category->value)->map->sum('amount_minor');
        $byStatus = Expense::query()->where('project_id', $project->id)
            ->whereIn('status', [ExpenseStatus::Approved, ExpenseStatus::Paid])
            ->groupBy('category', 'status')->selectRaw('category, status, sum(amount_minor) as amount')
            ->get();
        $sum = fn (string $category, ExpenseStatus $status) => (int) $byStatus
            ->filter(fn (Expense $row) => $row->category->value === $category && $row->status === $status)
            ->sum(fn (Expense $row) => (int) $row->getAttribute('amount'));

        $categories = collect(CostCategory::cases())->map(function (CostCategory $category) use ($planned, $sum, $metrics) {
            $budget = (int) ($planned[$category->value] ?? 0);
            $committed = $sum($category->value, ExpenseStatus::Approved);
            $spent = $category === CostCategory::Labour ? $metrics['labourMinor'] : $sum($category->value, ExpenseStatus::Paid);

            return [
                'category' => $category->value,
                'label' => $category->label(),
                'budgetMinor' => $budget,
                'committedMinor' => $committed,
                'actualMinor' => $spent,
                'availableMinor' => $budget - $committed - $spent,
            ];
        })->filter(fn (array $row) => $row['budgetMinor'] > 0 || $row['actualMinor'] > 0 || $row['committedMinor'] > 0)->values();

        $charged = Expense::query()->where('project_id', $project->id)->whereNotNull('grant_id')
            ->whereIn('status', [ExpenseStatus::Approved, ExpenseStatus::Paid])
            ->groupBy('grant_id', 'status')->selectRaw('grant_id, status, sum(amount_minor) as amount')
            ->get();

        return Inertia::render('portal/projects/budget', [
            'project' => $this->projectHeader($request, $project),
            'budgetMinor' => $project->budget_minor,
            'basis' => $approved !== null ? 'approved' : ($working !== null ? 'draft' : 'none'),
            'actualMinor' => $metrics['actualMinor'],
            'committedMinor' => $categories->sum('committedMinor'),
            'byCategory' => $categories,
            'versions' => $versions->reverse()->values()->map(fn (BudgetVersion $version) => [
                'id' => $version->id,
                'number' => $version->number,
                'status' => $version->status->value,
                'statusLabel' => $version->status->label(),
                'totalMinor' => $version->total_minor,
                'note' => $version->note,
                'returnedReason' => $version->returned_reason,
                'submittedBy' => $version->submitter?->name,
                'submittedAt' => $version->submitted_at?->toIso8601String(),
                'approvedBy' => $version->approver?->name,
                'approvedAt' => $version->approved_at?->toIso8601String(),
                'mine' => $version->submitted_by === $request->user()?->getAuthIdentifier(),
                'lines' => $version->lines->sortBy(fn (BudgetLine $line) => $line->category->value)->values()->map(fn (BudgetLine $line) => [
                    'id' => $line->id,
                    'category' => $line->category->label(),
                    'description' => $line->description,
                    'amountMinor' => $line->amount_minor,
                ]),
            ]),
            // The grants paying for it, in the project's currency at the
            // rate recorded on each allocation, and what is charged to each.
            'funding' => $project->grantAllocations()->with('grant:id,reference,title,status,currency,funder_id', 'grant.funder:id,name')->get()
                ->sortBy('grant.reference')->values()
                ->map(fn (GrantAllocation $allocation) => [
                    'id' => $allocation->id,
                    'grantId' => $allocation->grant_id,
                    'reference' => $allocation->grant->reference,
                    'title' => $allocation->grant->title,
                    'funder' => $allocation->grant->funder->name,
                    'status' => $allocation->grant->status->value,
                    'grantCurrency' => $allocation->grant->currency,
                    'amountMinor' => $allocation->amount_minor,
                    'projectAmountMinor' => $allocation->project_amount_minor,
                    'committedMinor' => (int) $charged->where('grant_id', $allocation->grant_id)->where('status', ExpenseStatus::Approved)->sum(fn (Expense $row) => (int) $row->getAttribute('amount')),
                    'spentMinor' => (int) $charged->where('grant_id', $allocation->grant_id)->where('status', ExpenseStatus::Paid)->sum(fn (Expense $row) => (int) $row->getAttribute('amount')),
                ]),
            'canViewGrants' => app(CurrentOrganization::class)->require()->hasModule(Modules::GRANTS) && $this->can($request, 'grants.view'),
            'canApprove' => $this->can($request, 'finance.budgets.approve'),
            'categories' => CostCategory::options(),
        ]);
    }

    /**
     * A line of the draft version (started when there is none).
     */
    public function addLine(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(CostCategory::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'string', Money::rule($project->currency)],
        ]);

        Budgets::addLine(
            $project,
            CostCategory::from($validated['category']),
            $this->text($validated['description'] ?? null),
            Money::toMinor($validated['amount'], $project->currency),
            $this->member($request),
        );

        return back()->with('success', 'Budget line added.');
    }

    public function removeLine(Request $request, Project $project, BudgetLine $budgetLine): RedirectResponse
    {
        Budgets::removeLine($project, $budgetLine, $this->member($request));

        return back()->with('success', 'Budget line removed.');
    }

    /**
     * A new draft from the approved version, to change it.
     */
    public function revise(Request $request, Project $project): RedirectResponse
    {
        $version = Budgets::revise($project, $this->member($request));

        return back()->with('success', "Drafting version {$version->number}.");
    }

    public function discard(Request $request, Project $project, BudgetVersion $budgetVersion): RedirectResponse
    {
        Budgets::discard($project, $budgetVersion, $this->member($request));

        return back()->with('success', "Version {$budgetVersion->number} discarded.");
    }

    public function submit(Request $request, Project $project, BudgetVersion $budgetVersion): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        Budgets::submit($project, $budgetVersion, $this->text($validated['note'] ?? null), $this->member($request));

        return back()->with('success', "Version {$budgetVersion->number} submitted for approval.");
    }

    public function approve(Request $request, Project $project, BudgetVersion $budgetVersion): RedirectResponse
    {
        Budgets::approve($project, $budgetVersion, $this->member($request));

        return back()->with('success', "Version {$budgetVersion->number} approved.");
    }

    public function returnToDraft(Request $request, Project $project, BudgetVersion $budgetVersion): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        Budgets::returnToDraft($project, $budgetVersion, trim($validated['reason']), $this->member($request));

        return back()->with('success', "Version {$budgetVersion->number} returned to draft.");
    }
}
