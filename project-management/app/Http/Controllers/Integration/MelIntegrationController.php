<?php

namespace App\Http\Controllers\Integration;

use App\Access\CurrentAccess;
use App\Http\Controllers\Controller;
use App\Models\Milestone;
use App\Models\Project;
use App\Support\ProjectFinance;
use App\Support\ProjectMetrics;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What M&E may read from Projects (ADR-0019 §20), for the member it acts
 * for (App\Http\Middleware\EnterIntegration). Read only. Projects stays the
 * record: M&E keeps project and milestone ids, never copies of these.
 *
 *   GET projects                   the projects the member may link to:
 *                                  {id, code, name, label, status}
 *   GET projects?ids[]=…           figures for those projects (unknown or
 *       [&with=milestones]         not visible ones listed in "missing")
 *   GET projects/{id}              one project's figures and milestones,
 *                                  404 unknown_project
 *   GET milestones?ids[]=…         milestones by id, or of projects:
 *   GET milestones?project_ids[]=… {id, projectId, projectCode, name,
 *                                  label, dueOn, reachedOn}
 *
 * Figures: status and delivery progress; the approved budget and what has
 * been paid only for members holding projects.finance.view. A project that
 * is completed or cancelled is still answered (with its status): M&E keeps
 * results linked to it.
 */
final class MelIntegrationController extends Controller
{
    private const MAX_IDS = 100;

    private const MAX_LIST = 500;

    public function __construct(private readonly CurrentAccess $access) {}

    public function projects(Request $request): JsonResponse
    {
        if (! $request->has('ids')) {
            $projects = Project::query()->orderBy('code')->limit(self::MAX_LIST)->get(['id', 'code', 'name', 'status']);

            return response()->json(['data' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'label' => "{$project->code} · {$project->name}",
                'status' => $project->status,
            ])->values()]);
        }

        $ids = $this->ids($request, 'ids');
        if ($ids === null) {
            return $this->invalid('ids');
        }

        $projects = Project::query()->whereIn('id', $ids)->get();
        $figures = $this->figures($projects, $request->query('with') === 'milestones');

        return response()->json([
            'data' => $figures,
            'missing' => array_values(array_diff($ids, $projects->pluck('id')->all())),
        ]);
    }

    public function project(string $project): JsonResponse
    {
        $found = Str::isUuid($project) ? Project::query()->find($project) : null;
        if ($found === null) {
            return response()->json(['error' => 'unknown_project'], 404);
        }

        return response()->json(['data' => $this->figures($found->newCollection([$found]), true)[0]]);
    }

    public function milestones(Request $request): JsonResponse
    {
        $byProject = $request->has('project_ids');
        $ids = $this->ids($request, $byProject ? 'project_ids' : 'ids');
        if ($ids === null) {
            return $this->invalid($byProject ? 'project_ids' : 'ids');
        }

        // Only milestones of projects the member may see.
        $visible = Project::query()->select('id');
        $milestones = Milestone::query()
            ->with('project:id,code')
            ->whereIn($byProject ? 'project_id' : 'id', $ids)
            ->whereIn('project_id', $visible)
            ->orderBy('due_on')
            ->get();

        return response()->json(['data' => $milestones->map(fn (Milestone $milestone) => $this->milestone($milestone))->values()]);
    }

    /**
     * @param  EloquentCollection<int, Project>  $projects
     * @return list<array<string, mixed>>
     */
    private function figures(EloquentCollection $projects, bool $withMilestones): array
    {
        $metrics = ProjectMetrics::forProjects($projects);
        $money = $this->access->get()?->has('projects.finance.view') === true ? ProjectFinance::forProjects($projects) : [];
        if ($withMilestones) {
            $projects->load(['milestones' => fn ($query) => $query->orderBy('due_on')]);
        }

        return array_values($projects->map(function (Project $project) use ($metrics, $money, $withMilestones) {
            $figures = [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'label' => "{$project->code} · {$project->name}",
                'status' => $project->status,
                'progressPercent' => isset($metrics[$project->id]) ? max(0, min(100, (int) $metrics[$project->id]['progress'])) : null,
                'currency' => $project->currency,
                'approvedBudgetMinor' => $money[$project->id]['approvedBudgetMinor'] ?? null,
                'paidMinor' => isset($money[$project->id]) ? $money[$project->id]['paidMinor'] : null,
                'paidStatus' => isset($money[$project->id]) ? $money[$project->id]['provenance']['paidStatus'] : null,
            ];
            if ($withMilestones) {
                $figures['milestones'] = $project->milestones->map(fn (Milestone $milestone) => $this->milestone($milestone->setRelation('project', $project)))->values()->all();
            }

            return $figures;
        })->all());
    }

    /**
     * @return array{id: string, projectId: string, projectCode: string, name: string, label: string, dueOn: string, reachedOn: string|null}
     */
    private function milestone(Milestone $milestone): array
    {
        return [
            'id' => $milestone->id,
            'projectId' => $milestone->project_id,
            'projectCode' => $milestone->project->code,
            'name' => $milestone->name,
            'label' => "{$milestone->project->code} · {$milestone->name}",
            'dueOn' => $milestone->due_on->toDateString(),
            'reachedOn' => $milestone->completed_at?->toDateString(),
        ];
    }

    /**
     * The ids asked for: a list of 1 to 100 uuids, or null when malformed.
     *
     * @return list<string>|null
     */
    private function ids(Request $request, string $key): ?array
    {
        $ids = $request->query($key);
        if (! is_array($ids) || $ids === [] || count($ids) > self::MAX_IDS) {
            return null;
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                return null;
            }
        }

        /** @var list<string> */
        return array_values(array_unique(array_map('strtolower', $ids)));
    }

    private function invalid(string $field): JsonResponse
    {
        return response()->json(['error' => 'invalid', 'detail' => "{$field}[] must be 1 to ".self::MAX_IDS.' ids.'], 422);
    }
}
