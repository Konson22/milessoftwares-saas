<?php

namespace App\Http\Controllers\Projects;

use App\Actions\ChangeRequests;
use App\Http\Controllers\Concerns\ShowsProject;
use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Requests to change a project under way. A budget change shows only to
 * those who may see a project's money, and only they may ask for or approve one.
 */
class ChangeRequestController extends Controller
{
    use ShowsProject;

    public function index(Request $request, Project $project): Response
    {
        $finance = $this->can($request, 'projects.finance.view');
        $canDecide = $this->can($request, 'projects.changes.approve');
        $canManage = $this->can($request, 'projects.manage');
        $me = $this->member($request)->id;

        $changes = $project->changeRequests()
            ->with('raiser:id,name', 'decider:id,name', 'budgetVersion:id,number,status')
            ->orderByDesc('number')->get();

        return Inertia::render('portal/projects/changes', [
            'project' => $this->projectHeader($request, $project),
            'changes' => $changes->map(fn (ChangeRequest $change) => [
                'id' => $change->id,
                'reference' => $change->reference($project->code),
                'title' => $change->title,
                'description' => $change->description,
                'status' => $change->status,
                'proposedDueOn' => $change->proposed_due_on?->toDateString(),
                'previousDueOn' => $change->previous_due_on?->toDateString(),
                'asksBudget' => $change->budget_change_minor !== null,
                'budgetChangeMinor' => $finance ? $change->budget_change_minor : null,
                'budgetVersion' => $finance && $change->budgetVersion !== null ? [
                    'number' => $change->budgetVersion->number,
                    'status' => $change->budgetVersion->status->value,
                ] : null,
                'raisedBy' => $change->raiser->name,
                'raisedAt' => $change->created_at?->toIso8601String(),
                'decidedBy' => $change->decider?->name,
                'decidedAt' => $change->decided_at?->toIso8601String(),
                'decisionNote' => $change->decision_note,
                'canDecide' => $canDecide && $change->isSubmitted() && $change->raised_by !== $me && ($finance || $change->budget_change_minor === null),
                'canWithdraw' => $change->isSubmitted() && ($canManage || $change->raised_by === $me),
                'canRestartBudget' => $canManage && $finance && $project->isOpen() && $change->status === ChangeRequest::APPROVED
                    && $change->budget_change_minor !== null && $change->budget_version_id === null,
            ]),
            'canRaise' => $project->isOpen() && $this->can($request, 'projects.tasks.manage'),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'proposed_due_on' => array_filter(['nullable', 'date', $project->start_on === null ? null : "after_or_equal:{$project->start_on->toDateString()}"]),
            'budget_direction' => ['nullable', 'in:increase,decrease', 'required_with:budget_change'],
            'budget_change' => ['nullable', 'string', Money::rule($project->currency)],
        ]);

        $amount = blank($validated['budget_change'] ?? null) ? null : Money::toMinor((string) $validated['budget_change'], $project->currency);
        if ($amount !== null && ! $this->can($request, 'projects.finance.view')) {
            throw ValidationException::withMessages(['budget_change' => 'Asking for a budget change needs access to the budget.']);
        }
        if ($amount === 0) {
            throw ValidationException::withMessages(['budget_change' => 'Enter the amount of the change.']);
        }

        $change = ChangeRequests::raise($project, [
            'title' => trim((string) $validated['title']),
            'description' => trim((string) $validated['description']),
            'proposed_due_on' => $validated['proposed_due_on'] ?? null,
            'budget_change_minor' => $amount === null ? null : (($validated['budget_direction'] ?? null) === 'decrease' ? -$amount : $amount),
        ], $this->member($request));

        return back()->with('success', "{$change->reference($project->code)} raised for a decision.");
    }

    public function approve(Request $request, Project $project, ChangeRequest $changeRequest): RedirectResponse
    {
        // Nobody approves an amount they may not see.
        if ($changeRequest->budget_change_minor !== null && ! $this->can($request, 'projects.finance.view')) {
            throw ValidationException::withMessages(['change' => 'This request changes the budget: deciding it needs access to the budget.']);
        }
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:5000']]);

        ChangeRequests::approve($changeRequest, $this->text($validated['note'] ?? null), $this->member($request));

        return back()->with('success', $changeRequest->budget_change_minor === null
            ? "{$changeRequest->reference($project->code)} approved."
            : "{$changeRequest->reference($project->code)} approved. Write the budget change in the draft version, then submit it for approval.");
    }

    public function reject(Request $request, Project $project, ChangeRequest $changeRequest): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:5000']]);

        ChangeRequests::reject($changeRequest, trim((string) $validated['note']), $this->member($request));

        return back()->with('success', "{$changeRequest->reference($project->code)} rejected.");
    }

    public function withdraw(Request $request, Project $project, ChangeRequest $changeRequest): RedirectResponse
    {
        $user = $this->member($request);
        abort_unless($changeRequest->raised_by === $user->id || $this->can($request, 'projects.manage'), 403);

        ChangeRequests::withdraw($changeRequest, $user);

        return back()->with('success', "{$changeRequest->reference($project->code)} withdrawn.");
    }

    public function restartBudget(Request $request, Project $project, ChangeRequest $changeRequest): RedirectResponse
    {
        ChangeRequests::restartBudget($changeRequest, $this->member($request));

        return back()->with('success', 'A new budget draft is ready to write.');
    }
}
