<?php

namespace App\Http\Controllers\Work;

use App\Actions\WorkStructure;
use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Project;
use App\Models\Space;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\Workspace;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spaces: the organization's work structure as a tree (Workspace → Space
 * → Folder → List), and organizing it. The rules are in
 * App\Actions\WorkStructure.
 */
class StructureController extends Controller
{
    public function index(Request $request): Response
    {
        $lists = TaskList::query()->active()
            ->with('project:id,code,name')
            ->withCount(['tasks', 'tasks as open_count' => fn ($query) => $query->where('status', '!=', Task::DONE)])
            ->orderBy('position')->orderBy('name')->get();
        $folders = Folder::query()->active()->orderBy('position')->orderBy('name')->get();
        $spaces = Space::query()->active()->orderBy('position')->orderBy('name')->get();

        $list = fn (TaskList $list) => [
            'id' => $list->id,
            'name' => $list->name,
            'project' => $list->project?->code,
            'projectName' => $list->project?->name,
            'tasks' => (int) $list->getAttribute('tasks_count'),
            'open' => (int) $list->getAttribute('open_count'),
        ];
        $folder = fn (Folder $folder) => [
            'id' => $folder->id,
            'name' => $folder->name,
            'lists' => $lists->where('folder_id', $folder->id)->map($list)->values()->all(),
        ];
        $space = fn (Space $space) => [
            'id' => $space->id,
            'name' => $space->name,
            'folders' => $folders->where('space_id', $space->id)->map($folder)->values()->all(),
            // Lists straight in the space, outside any folder.
            'lists' => $lists->where('space_id', $space->id)->whereNull('folder_id')->map($list)->values()->all(),
        ];

        return Inertia::render('portal/work/spaces', [
            'workspaces' => Workspace::query()->active()->orderBy('position')->orderBy('name')->get()
                ->map(fn (Workspace $workspace) => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'spaces' => $spaces->where('workspace_id', $workspace->id)->map($space)->values()->all(),
                ])->values()->all(),
            'projects' => Project::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => "{$project->code} · {$project->name}"])->values()->all(),
            'canManage' => $this->can($request, 'work.structure.manage'),
        ]);
    }

    public function storeWorkspace(Request $request): RedirectResponse
    {
        $workspace = WorkStructure::createWorkspace($this->name($request), $this->member($request));

        return back()->with('success', "{$workspace->name} added.");
    }

    public function storeSpace(Request $request): RedirectResponse
    {
        $request->validate(['workspace_id' => ['required', 'uuid']]);
        $workspace = Workspace::query()->findOrFail($request->string('workspace_id')->toString());
        $space = WorkStructure::createSpace($workspace, $this->name($request), $this->member($request));

        return back()->with('success', "{$space->name} added.");
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $request->validate(['space_id' => ['required', 'uuid']]);
        $space = Space::query()->findOrFail($request->string('space_id')->toString());
        $folder = WorkStructure::createFolder($space, $this->name($request), $this->member($request));

        return back()->with('success', "{$folder->name} added.");
    }

    public function storeList(Request $request): RedirectResponse
    {
        $request->validate([
            'space_id' => ['required', 'uuid'],
            'folder_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid', Rule::exists('projects', 'id')->where('organization_id', app(CurrentOrganization::class)->id())],
        ]);
        $space = Space::query()->findOrFail($request->string('space_id')->toString());
        $folderId = $request->string('folder_id')->toString();
        $folder = $folderId === '' ? null : Folder::query()->findOrFail($folderId);
        $projectId = $request->string('project_id')->toString();
        $project = $projectId === '' ? null : Project::query()->findOrFail($projectId);

        $list = WorkStructure::createList($space, $folder, $project, $this->name($request), $this->member($request));

        return back()->with('success', $project === null ? "{$list->name} added." : "{$list->name} added for {$project->code}.");
    }

    public function rename(Request $request, string $kind, string $id): RedirectResponse
    {
        WorkStructure::rename($this->find($kind, $id), $this->name($request), $this->member($request));

        return back()->with('success', 'Renamed.');
    }

    public function archive(Request $request, string $kind, string $id): RedirectResponse
    {
        $part = $this->find($kind, $id);
        WorkStructure::archive($part, $this->member($request));

        return back()->with('success', "{$part->name} archived.");
    }

    /**
     * Scoped to the organization (BelongsToOrganization): another's is 404.
     */
    private function find(string $kind, string $id): Workspace|Space|Folder|TaskList
    {
        return match ($kind) {
            'workspace' => Workspace::query()->findOrFail($id),
            'space' => Space::query()->findOrFail($id),
            'folder' => Folder::query()->findOrFail($id),
            default => TaskList::query()->findOrFail($id),
        };
    }

    private function name(Request $request): string
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);

        return trim($validated['name']);
    }
}
