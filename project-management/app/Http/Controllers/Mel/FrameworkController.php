<?php

namespace App\Http\Controllers\Mel;

use App\Mel\Evidence;
use App\Mel\Frameworks;
use App\Mel\Members;
use App\Mel\Models\EvidenceFile;
use App\Mel\Models\Indicator;
use App\Mel\Models\Programme;
use App\Mel\Models\Result;
use App\Mel\Models\ResultLevel;
use App\Models\Milestone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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
            ->with(['responsible', 'indicators.currentVersion', 'linkedMilestone.project'])
            ->when(! $showArchived, fn ($query) => $query->whereNull('archived_at'))
            ->get();
        $documents = EvidenceFile::query()->where('programme_id', $programme->id)->whereNotNull('result_id')->get()->groupBy('result_id');
        $manages = $this->access()->can('mel.frameworks.manage') && ! $programme->isArchived();

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
                'milestone' => $result->linkedMilestone === null ? null : [
                    'name' => $result->linkedMilestone->name,
                    'project' => $result->linkedMilestone->project->code,
                    'dueOn' => $result->linkedMilestone->due_on->toDateString(),
                    'reachedOn' => $result->linkedMilestone->completed_at?->toDateString(),
                ],
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
                'milestones' => Milestone::query()
                    ->whereIn('project_id', $programme->units()->whereNotNull('linked_project_id')->select('linked_project_id'))
                    ->with('project')->orderBy('due_on')->get()
                    ->map(fn (Milestone $milestone) => ['value' => $milestone->id, 'label' => "{$milestone->project->code} · {$milestone->name}"]),
            ] : null,
            'can' => ['manage' => $manages],
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
            'linked_milestone_id' => ['nullable', 'uuid', Rule::exists('milestones', 'id')->where('organization_id', tenant_id())],
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
            'linked_milestone_id' => $data['linked_milestone_id'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
