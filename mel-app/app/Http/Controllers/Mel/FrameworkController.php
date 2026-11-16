<?php

namespace App\Http\Controllers\Mel;

use App\Mel\Evidence;
use App\Mel\Frameworks;
use App\Mel\LinkedProjects;
use App\Mel\Members;
use App\Mel\Models\EvidenceFile;
use App\Mel\Models\Indicator;
use App\Mel\Models\Programme;
use App\Mel\Models\Result;
use App\Mel\Models\ResultLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Results frameworks: the logical framework matrix of each programme, its
 * levels and results.
 */
class FrameworkController extends Controller
{
    public function __construct(
        private readonly Frameworks $frameworks,
        private readonly Evidence $evidence,
    ) {}

    public function index(): Response
    {
        return Inertia::render('mel/frameworks/index', [
            'programmes' => $this->access()->programmes()->whereNull('archived_at')
                ->withCount(['results' => fn ($query) => $query->whereNull('archived_at'), 'indicators'])
                ->with('levels')
                ->orderBy('code')->get()
                ->map(fn (Programme $programme) => [
                    'id' => $programme->id,
                    'code' => $programme->code,
                    'name' => $programme->name,
                    'levels' => $programme->levels->pluck('name')->all(),
                    'results' => $programme->results_count,
                    'indicators' => $programme->indicators_count,
                ]),
        ]);
    }

    public function show(Request $request, string $programme): Response
    {
        $programme = $this->programme($programme);
        $showArchived = $request->boolean('archived');
        $levels = $programme->levels()->get();
        $results = $programme->results()
            ->with(['responsible', 'indicators.currentVersion'])
            ->when(! $showArchived, fn ($query) => $query->whereNull('archived_at'))
            ->get();
        $documents = EvidenceFile::query()->where('programme_id', $programme->id)->whereNotNull('result_id')->get()->groupBy('result_id');
        $manages = $this->access()->can('mel.frameworks.manage') && ! $programme->isArchived();
        // Milestones are Projects' records, read live as this member sees them.
        $linked = array_values(array_unique(array_map(strval(...), array_filter($results->pluck('linked_milestone_id')->all()))));
        $milestones = LinkedProjects::milestones($this->access(), $linked);
        $choices = $manages ? $this->milestoneOptions($programme, $linked) : null;

        return Inertia::render('mel/frameworks/show', [
            'programme' => ['id' => $programme->id, 'code' => $programme->code, 'name' => $programme->name, 'archived' => $programme->isArchived()],
            'levels' => $levels->map(fn (ResultLevel $level) => ['id' => $level->id, 'depth' => $level->depth, 'name' => $level->name])->values(),
            'results' => self::inTreeOrder($results)->map(fn (Result $result) => [
                'id' => $result->id,
                'levelId' => $result->level_id,
                'parentId' => $result->parent_id,
                'code' => $result->code,
                'statement' => $result->statement,
                'description' => $result->description,
                'assumptions' => $result->assumptions,
                'risks' => $result->risks,
                'meansOfVerification' => $result->means_of_verification,
                'responsibleId' => $result->responsible_id,
                'responsible' => $result->responsible?->name,
                'linkedMilestoneId' => $result->linked_milestone_id,
                'milestone' => self::milestone($result->linked_milestone_id === null ? null : ($milestones['milestones'][$result->linked_milestone_id] ?? null)),
                'archived' => $result->archived_at !== null,
                'indicators' => $result->indicators->sortBy('code')->map(fn (Indicator $indicator) => [
                    'id' => $indicator->id,
                    'code' => $indicator->code,
                    'name' => $indicator->currentVersion->name ?? '',
                    'status' => $indicator->status,
                    'meansOfVerification' => $indicator->currentVersion?->means_of_verification,
                ])->values(),
                'documents' => $documents->get($result->id, collect())->map(fn (EvidenceFile $file) => [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'size' => $file->size,
                ])->values(),
            ])->values(),
            'showArchived' => $showArchived,
            'options' => $manages ? [
                'members' => Members::options(),
                'milestones' => $choices ?? [],
            ] : null,
            'can' => ['manage' => $manages],
            'projectLink' => ['status' => $milestones['status'], 'message' => $milestones['message']],
        ]);
    }

    public function levels(Request $request, string $programme): RedirectResponse
    {
        $programme = $this->programme($programme);
        $names = $request->validate([
            'levels' => ['required', 'array', 'min:1', 'max:6'],
            'levels.*' => ['required', 'string', 'max:48', 'distinct'],
        ])['levels'];

        $this->frameworks->setLevels($programme, array_values($names), $this->user());

        return back()->with('success', 'Levels saved.');
    }

    public function storeResult(Request $request, string $programme): RedirectResponse
    {
        $programme = $this->programme($programme);
        $this->frameworks->save($programme, null, $this->resultData($request, $programme, null), $this->user());

        return back()->with('success', 'Result added.');
    }

    public function updateResult(Request $request, string $programme, string $result): RedirectResponse
    {
        $programme = $this->programme($programme);
        $result = $programme->results()->findOrFail($result);
        $this->frameworks->save($programme, $result, $this->resultData($request, $programme, $result), $this->user());

        return back()->with('success', 'Result saved.');
    }

    public function archiveResult(Request $request, string $programme, string $result): RedirectResponse
    {
        $programme = $this->programme($programme);
        $this->frameworks->archive($programme->results()->findOrFail($result), ! $request->boolean('restore'), $this->user());

        return back()->with('success', $request->boolean('restore') ? 'Result restored.' : 'Result archived.');
    }

    public function attach(Request $request, string $programme, string $result): RedirectResponse
    {
        $programme = $this->programme($programme);
        $result = $programme->results()->findOrFail($result);
        $data = $request->validate(['file' => ['required', 'file', 'max:'.Evidence::MAX_KILOBYTES], 'share_with_donors' => ['boolean']]);
        $file = $request->file('file');
        assert($file instanceof UploadedFile);

        $this->evidence->attachToResult($result, $file, (bool) ($data['share_with_donors'] ?? false), $this->user());

        return back()->with('success', 'Document added.');
    }

    /**
     * Parents before children, siblings by code ("1.2" before "1.10").
     *
     * @param  Collection<int, Result>  $results
     * @return Collection<int, Result>
     */
    public static function inTreeOrder(Collection $results): Collection
    {
        $children = $results->groupBy(fn (Result $result) => $result->parent_id ?? '');
        $ids = $results->pluck('id')->flip();
        $ordered = collect();

        $walk = function (string $parent) use (&$walk, $children, $ordered): void {
            foreach ($children->get($parent, collect())->sort(fn (Result $a, Result $b) => [$a->sort_order, strnatcmp($a->code, $b->code)] <=> [$b->sort_order, 0]) as $result) {
                $ordered->push($result);
                $walk($result->id);
            }
        };

        $walk('');
        // Results whose parent is hidden (archived) still show, at the end.
        $results->each(function (Result $result) use ($ordered, $ids, $walk) {
            if ($result->parent_id !== null && ! $ids->has($result->parent_id) && ! $ordered->contains('id', $result->id)) {
                $ordered->push($result);
                $walk($result->id);
            }
        });

        return $ordered;
    }

    /**
     * @return array{level_id: string, parent_id: string|null, code: string, statement: string, description: string|null, assumptions: string|null, risks: string|null, means_of_verification: string|null, responsible_id: string|null, linked_milestone_id: string|null, sort_order: int}
     */
    private function resultData(Request $request, Programme $programme, ?Result $result): array
    {
        $data = $request->validate([
            'level_id' => ['required', 'uuid'],
            'parent_id' => ['nullable', 'uuid'],
            'code' => ['required', 'string', 'max:24', Rule::unique('mel_results', 'code')->where('programme_id', $programme->id)->ignore($result?->id)],
            'statement' => ['required', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assumptions' => ['nullable', 'string', 'max:5000'],
            'risks' => ['nullable', 'string', 'max:5000'],
            'means_of_verification' => ['nullable', 'string', 'max:5000'],
            'responsible_id' => ['nullable', 'uuid', Members::rule()],
            'linked_milestone_id' => ['nullable', 'uuid'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        return [
            'level_id' => $data['level_id'],
            'parent_id' => $data['parent_id'] ?? null,
            'code' => $data['code'],
            'statement' => $data['statement'],
            'description' => $data['description'] ?? null,
            'assumptions' => $data['assumptions'] ?? null,
            'risks' => $data['risks'] ?? null,
            'means_of_verification' => $data['means_of_verification'] ?? null,
            'responsible_id' => $data['responsible_id'] ?? null,
            'linked_milestone_id' => $this->milestoneReference($programme, $result, $data['linked_milestone_id'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * The milestone a result points to: checked in Projects when it changes
     * (a milestone of a project linked to this programme, that the member
     * may see there). While the link is off, the reference stays as it was:
     * it is never set or cleared without Projects.
     */
    private function milestoneReference(Programme $programme, ?Result $result, ?string $given): ?string
    {
        $current = $result?->linked_milestone_id;
        if (! LinkedProjects::enabled()) {
            return $current;
        }
        $given = $given === null ? null : strtolower($given);
        if ($given === null || $given === $current) {
            return $given;
        }

        $read = LinkedProjects::milestones($this->access(), [$given]);
        if ($read['status'] !== 'ok') {
            throw ValidationException::withMessages(['linked_milestone_id' => $read['message'] ?? 'Projects is not connected.']);
        }
        $milestone = $read['milestones'][$given] ?? null;
        if ($milestone === null || ! in_array((string) ($milestone['projectId'] ?? ''), $this->linkedProjectIds($programme), true)) {
            throw ValidationException::withMessages(['linked_milestone_id' => 'Choose a milestone of a project linked to one of this programme\'s units.']);
        }

        return $given;
    }

    /**
     * Milestones of the projects linked to the programme's units, and any
     * milestone a result already points to that Projects did not list now,
     * so saving a result never drops its reference unasked.
     *
     * @param  list<string>  $kept
     * @return list<array{value: string, label: string}>
     */
    private function milestoneOptions(Programme $programme, array $kept): array
    {
        $read = LinkedProjects::enabled() ? LinkedProjects::milestones($this->access(), $this->linkedProjectIds($programme), ofProjects: true) : ['milestones' => []];

        $options = array_values(array_map(fn (array $milestone) => ['value' => (string) $milestone['id'], 'label' => (string) $milestone['label']], $read['milestones']));
        foreach (array_diff($kept, array_keys($read['milestones'])) as $id) {
            $options[] = ['value' => $id, 'label' => 'Milestone kept from before (not shown by Projects now)'];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function linkedProjectIds(Programme $programme): array
    {
        return array_values($programme->units()->whereNotNull('linked_project_id')->pluck('linked_project_id')->map(fn (mixed $id) => (string) $id)->all());
    }

    /**
     * @param  array<string, mixed>|null  $milestone  as Projects answered
     * @return array{name: string, project: string, dueOn: string|null, reachedOn: string|null}|null
     */
    private static function milestone(?array $milestone): ?array
    {
        return $milestone === null ? null : [
            'name' => (string) $milestone['name'],
            'project' => (string) $milestone['projectCode'],
            'dueOn' => is_string($milestone['dueOn'] ?? null) ? $milestone['dueOn'] : null,
            'reachedOn' => is_string($milestone['reachedOn'] ?? null) ? $milestone['reachedOn'] : null,
        ];
    }
}
