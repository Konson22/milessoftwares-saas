<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use App\Support\ProjectMetrics;
use Illuminate\Http\Request;

/**
 * What every page of a project shows above its tab: the project, how it
 * stands, and what the member may do on it.
 */
trait ShowsProject
{
    /**
     * @return array<string, mixed>
     */
    protected function projectHeader(Request $request, Project $project): array
    {
        $project->loadMissing('manager:id,name');
        $metrics = ProjectMetrics::for($project);
        $finance = $request->user()?->can('projects.finance.view') ?? false;

        return [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'client' => $project->client,
            'status' => $project->status,
            'open' => $project->isOpen(),
            'manager' => $project->manager?->name,
            'startOn' => $project->start_on?->toDateString(),
            'dueOn' => $project->due_on?->toDateString(),
            'currency' => $project->currency,
            'progress' => $metrics['progress'],
            'health' => $metrics['health'],
            'healthReasons' => $metrics['healthReasons'],
            'can' => [
                'manage' => $request->user()?->can('projects.manage') ?? false,
                'tasks' => $request->user()?->can('projects.tasks.manage') ?? false,
                'logTime' => $request->user()?->can('projects.time.log') ?? false,
                'finance' => $finance,
            ],
        ];
    }
}
