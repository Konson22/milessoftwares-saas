<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ShowsProject;
use App\Models\Person;
use App\Models\Project;
use App\Models\Risk;
use App\Support\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * A project's risk register, scored probability × impact.
 */
class RiskController extends Controller
{
    use ShowsProject;

    public function index(Request $request, Project $project): Response
    {
        $risks = $project->risks()->with('owner:id,name')->get()
            ->sortBy([
                fn (Risk $a, Risk $b) => ($a->status === Risk::CLOSED) <=> ($b->status === Risk::CLOSED),
                fn (Risk $a, Risk $b) => $b->score() <=> $a->score(),
            ]);

        return Inertia::render('projects/risks', [
            'project' => $this->projectHeader($request, $project),
            'risks' => $risks->values()->map(fn (Risk $risk) => [
                'id' => $risk->id,
                'title' => $risk->title,
                'description' => $risk->description,
                'probability' => $risk->probability,
                'impact' => $risk->impact,
                'score' => $risk->score(),
                'level' => $risk->level(),
                'status' => $risk->status,
                'ownerId' => $risk->owner_id,
                'owner' => $risk->owner?->name,
                'mitigation' => $risk->mitigation,
                'reviewOn' => $risk->review_on?->toDateString(),
            ]),
            'people' => Person::options(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $risk = $project->risks()->create([...$this->details($request), 'status' => Risk::OPEN]);
        ActivityLog::record($project, $this->member($request), "Raised the risk {$risk->title} (score {$risk->score()})");

        return back()->with('success', 'Risk added to the register.');
    }

    public function update(Request $request, Project $project, Risk $risk): RedirectResponse
    {
        abort_unless($risk->project_id === $project->id, 404);
        $status = $request->validate(['status' => ['required', Rule::in(Risk::STATUSES)]])['status'];

        $was = $risk->status;
        $risk->fill([...$this->details($request), 'status' => $status])->save();
        if ($was !== $risk->status) {
            ActivityLog::record($project, $this->member($request), "Risk {$risk->title} is now {$risk->status}");
        }

        return back()->with('success', 'Risk saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'probability' => ['required', 'integer', 'between:1,5'],
            'impact' => ['required', 'integer', 'between:1,5'],
            'owner_id' => ['nullable', 'uuid', Rule::exists('people', 'id')->where('organization_id', app(CurrentTenant::class)->id())],
            'mitigation' => ['nullable', 'string', 'max:5000'],
            'review_on' => ['nullable', 'date'],
        ]);

        return [
            'title' => trim($validated['title']),
            'description' => $this->text($validated['description'] ?? null),
            'probability' => (int) $validated['probability'],
            'impact' => (int) $validated['impact'],
            'owner_id' => $validated['owner_id'] ?? null,
            'mitigation' => $this->text($validated['mitigation'] ?? null),
            'review_on' => $validated['review_on'] ?? null,
        ];
    }
}
