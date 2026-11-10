<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Deliverables;
use App\Http\Controllers\Concerns\ShowsProject;
use App\Http\Controllers\Controller;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Person;
use App\Models\Project;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a project promises to hand over, and whether it was accepted.
 */
class DeliverableController extends Controller
{
    use ShowsProject;

    public function index(Request $request, Project $project): Response
    {
        $deliverables = $project->deliverables()
            ->with('milestone:id,name', 'owner:id,name', 'deliverer:id,name', 'accepter:id,name')
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('created_at')->get();

        return Inertia::render('portal/projects/deliverables', [
            'project' => $this->projectHeader($request, $project),
            'deliverables' => $deliverables->map(fn (Deliverable $deliverable) => [
                'id' => $deliverable->id,
                'name' => $deliverable->name,
                'description' => $deliverable->description,
                'status' => $deliverable->status,
                'milestoneId' => $deliverable->milestone_id,
                'milestone' => $deliverable->milestone?->name,
                'ownerId' => $deliverable->owner_id,
                'owner' => $deliverable->owner?->name,
                'dueOn' => $deliverable->due_on?->toDateString(),
                'deliveryNote' => $deliverable->delivery_note,
                'deliveredBy' => $deliverable->deliverer?->name,
                'deliveredAt' => $deliverable->delivered_at?->toIso8601String(),
                'acceptedBy' => $deliverable->accepter?->name,
                'acceptedAt' => $deliverable->accepted_at?->toIso8601String(),
                'returnedReason' => $deliverable->returned_reason,
            ]),
            'milestones' => $project->milestones()->orderBy('due_on')->get(['id', 'name'])
                ->map(fn (Milestone $milestone) => ['value' => $milestone->id, 'label' => $milestone->name])->values()->all(),
            'people' => Person::options(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $deliverable = Deliverables::add($project, $this->details($request, $project), $this->member($request));

        return back()->with('success', "{$deliverable->name} added.");
    }

    public function update(Request $request, Project $project, Deliverable $deliverable): RedirectResponse
    {
        Deliverables::update($deliverable, $this->details($request, $project), $this->member($request));

        return back()->with('success', 'Deliverable saved.');
    }

    public function deliver(Request $request, Project $project, Deliverable $deliverable): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:5000']]);
        Deliverables::deliver($deliverable, $this->text($validated['note'] ?? null), $this->member($request));

        return back()->with('success', "{$deliverable->name} delivered.");
    }

    public function accept(Request $request, Project $project, Deliverable $deliverable): RedirectResponse
    {
        Deliverables::accept($deliverable, $this->member($request));

        return back()->with('success', "{$deliverable->name} accepted.");
    }

    public function sendBack(Request $request, Project $project, Deliverable $deliverable): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        Deliverables::sendBack($deliverable, trim((string) $validated['note']), $this->member($request));

        return back()->with('success', "{$deliverable->name} sent back.");
    }

    public function cancel(Request $request, Project $project, Deliverable $deliverable): RedirectResponse
    {
        Deliverables::cancel($deliverable, $this->member($request));

        return back()->with('success', "{$deliverable->name} cancelled.");
    }

    /**
     * @return array{name: string, description: string|null, milestone_id: string|null, owner_id: string|null, due_on: string|null}
     */
    private function details(Request $request, Project $project): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'milestone_id' => ['nullable', 'uuid', Rule::exists('milestones', 'id')->where('project_id', $project->id)],
            'owner_id' => ['nullable', 'uuid', Rule::exists('people', 'id')->where('organization_id', app(CurrentOrganization::class)->id())],
            'due_on' => ['nullable', 'date'],
        ]);

        return [
            'name' => trim((string) $validated['name']),
            'description' => $this->text($validated['description'] ?? null),
            'milestone_id' => $validated['milestone_id'] ?? null,
            'owner_id' => $validated['owner_id'] ?? null,
            'due_on' => $validated['due_on'] ?? null,
        ];
    }
}
