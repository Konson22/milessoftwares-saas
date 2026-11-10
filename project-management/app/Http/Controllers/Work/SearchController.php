<?php

namespace App\Http\Controllers\Work;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\Person;
use App\Models\Programme;
use App\Models\Project;
use App\Models\Task;
use App\Support\Modules;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Search across this organization, for what the member may already open:
 * each group is asked for only with the permission of the page it links to.
 */
class SearchController extends Controller
{
    public const MINIMUM = 2;

    public const PER_GROUP = 20;

    public function __invoke(Request $request): Response
    {
        $term = mb_substr(trim($request->string('q')->toString()), 0, 100);

        return Inertia::render('portal/search', [
            'term' => $term,
            'minimum' => self::MINIMUM,
            'groups' => mb_strlen($term) < self::MINIMUM ? [] : array_values(array_filter($this->groups($request, $term), fn (array $group) => $group['results'] !== [])),
        ]);
    }

    /**
     * @return list<array{title: string, results: list<array{id: string, label: string, hint: string|null, url: string}>}>
     */
    private function groups(Request $request, string $term): array
    {
        $groups = [];

        if ($this->can($request, 'projects.view')) {
            $groups[] = $this->group('Projects', Project::query(), ['code', 'name'], $term, fn (Project $project) => [
                'id' => $project->id,
                'label' => "{$project->code} · {$project->name}",
                'hint' => null,
                'url' => route('projects.show', $project, false),
            ]);
            $groups[] = $this->group('Programmes', Programme::query(), ['code', 'name'], $term, fn (Programme $programme) => [
                'id' => $programme->id,
                'label' => $programme->label(),
                'hint' => null,
                'url' => route('programmes.show', $programme, false),
            ]);
            $groups[] = $this->group('Tasks', Task::query()->with(['project:id,name', 'list:id,name']), ['number', 'title'], $term, fn (Task $task) => [
                'id' => $task->id,
                'label' => "{$task->number} · {$task->title}",
                'hint' => $task->project->name ?? $task->list->name,
                'url' => $task->project_id === null ? route('work.tasks.show', $task, false) : route('projects.tasks.show', ['project' => $task->project_id, 'task' => $task->id], false),
            ]);
            // Name and title only: the directory's costs have their own permission.
            $groups[] = $this->group('People', Person::query()->where('active', true), ['name', 'title'], $term, fn (Person $person) => [
                'id' => $person->id,
                'label' => $person->name,
                'hint' => $person->title,
                'url' => route('people.index', [], false),
            ]);
        }

        if ($this->can($request, 'documents.view') && app(CurrentOrganization::class)->require()->hasModule(Modules::DOCUMENTS)) {
            $documents = Document::query()->when(! $this->can($request, 'documents.manage'), fn ($query) => $query->openToAll());
            $groups[] = $this->group('Documents', $documents, ['title'], $term, fn (Document $document) => [
                'id' => $document->id,
                'label' => $document->title,
                'hint' => Document::CATEGORIES[$document->category] ?? null,
                'url' => route('documents.show', $document, false),
            ]);
        }

        if ($this->can($request, 'projects.finance.view')) {
            $groups[] = $this->group('Spending', Expense::query()->with('project:id,code'), ['description', 'supplier'], $term, fn (Expense $expense) => [
                'id' => $expense->id,
                'label' => $expense->description,
                'hint' => trim("{$expense->project->code} · ".($expense->supplier ?? ''), ' ·'),
                'url' => route('projects.expenses.index', ['project' => $expense->project_id], false),
            ]);
        }

        if ($this->can($request, 'grants.view') && app(CurrentOrganization::class)->require()->hasModule(Modules::GRANTS)) {
            $groups[] = $this->group('Grants', Grant::query()->with('funder:id,name'), ['reference', 'title'], $term, fn (Grant $grant) => [
                'id' => $grant->id,
                'label' => "{$grant->reference} · {$grant->title}",
                'hint' => $grant->funder->name,
                'url' => route('grants.show', $grant, false),
            ]);
            $groups[] = $this->group('Funders', Funder::query(), ['name'], $term, fn (Funder $funder) => [
                'id' => $funder->id,
                'label' => $funder->name,
                'hint' => null,
                'url' => route('funders.index', [], false),
            ]);
        }

        return $groups;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @param  callable(TModel): array{id: string, label: string, hint: string|null, url: string}  $row
     * @return array{title: string, results: list<array{id: string, label: string, hint: string|null, url: string}>}
     */
    private function group(string $title, Builder $query, array $columns, string $term, callable $row): array
    {
        // What was typed is matched as text: % and _ are not wildcards here.
        // Escaped with "!", which every database reads the same way; a backslash isn't.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        $found = $query->where(function (Builder $where) use ($columns, $like) {
            foreach ($columns as $column) {
                // The columns are this class's own, never what was typed.
                // @phpstan-ignore-next-line argument.type
                $where->orWhereRaw('LOWER('.$where->getGrammar()->wrap($column).") LIKE ? ESCAPE '!'", [$like]);
            }
        })->limit(self::PER_GROUP)->get();

        return ['title' => $title, 'results' => array_values($found->map($row)->all())];
    }
}
