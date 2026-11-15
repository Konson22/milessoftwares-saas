<?php

namespace App\Access;

use App\Models\Person;
use App\Models\Project;
use App\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Which projects the signed-in member may see inside their own organization.
 *
 * A project is either open to the organization (visibility "organization",
 * the default and what every project had before) or open to its team only
 * (visibility "team"). A team-only project is seen by:
 *   - the workspace owner and members holding projects.all;
 *   - its manager, the person who set it up and its team members
 *     (project_members, through the member's Person record).
 * Taking someone off the team takes the project away from them at once:
 * nothing is copied or cached beyond the request.
 *
 * It only narrows: it never grants projects.view or any other permission,
 * and it is not applied when no member is signed in (console, queue) or
 * when the code runs inside another organization on purpose (a participant
 * reading an owner's project through App\Collaboration, which checks
 * access itself).
 */
final class ProjectVisibility
{
    /** @var array<string, list<string>> person ids by "organization:user" */
    private array $people = [];

    public function __construct(
        private readonly CurrentAccess $access,
        private readonly CurrentOrganization $organization,
        private readonly Auth $auth,
    ) {}

    /**
     * Whether queries for the current member are narrowed to the projects
     * they may see.
     */
    public function restricted(): bool
    {
        $access = $this->access->get();
        $organizationId = $this->organization->id();
        if ($access === null || $organizationId === null || $access->organizationId !== $organizationId) {
            return false;
        }

        return ! $access->isOwner && ! $access->has('projects.all') && $this->userId() !== null;
    }

    /**
     * Narrows a projects query (the projects table itself).
     *
     * @param  Builder<covariant Project>  $query
     */
    public function apply(Builder $query): void
    {
        $model = $query->getModel();
        $people = $this->personIds();
        $userId = (string) $this->userId();
        $organizationId = (string) $this->organization->id();

        $query->where(function (Builder $query) use ($model, $people, $userId, $organizationId) {
            $query->where($model->qualifyColumn('visibility'), Project::VISIBLE_TO_ORGANIZATION)
                ->orWhere($model->qualifyColumn('created_by'), $userId);
            if ($people !== []) {
                $query->orWhereIn($model->qualifyColumn('manager_id'), $people)
                    ->orWhereIn($model->qualifyColumn('id'), fn (QueryBuilder $members) => $members
                        ->select('project_id')->from('project_members')
                        ->where('organization_id', $organizationId)
                        ->whereIn('person_id', $people));
            }
        });
    }

    /**
     * Whether the member may see this project (for code holding a project
     * it loaded some other way).
     */
    public function sees(Project $project): bool
    {
        if (! $this->restricted() || $project->visibility === Project::VISIBLE_TO_ORGANIZATION) {
            return true;
        }
        if ($project->created_by === $this->userId()) {
            return true;
        }
        $people = $this->personIds();

        return $people !== [] && (in_array($project->manager_id, $people, true)
            || DB::table('project_members')->where('project_id', $project->id)->whereIn('person_id', $people)->exists());
    }

    /**
     * Whether a member would still see the project with this visibility,
     * so nobody shuts themselves out of a project they manage settings for.
     */
    public function wouldSee(Project $project, string $visibility): bool
    {
        if ($visibility === Project::VISIBLE_TO_ORGANIZATION || ! $this->restricted()) {
            return true;
        }
        $copy = clone $project;
        $copy->visibility = $visibility;

        return $this->sees($copy);
    }

    /**
     * @return list<string>
     */
    private function personIds(): array
    {
        $key = $this->organization->id().':'.$this->userId();

        return $this->people[$key] ??= array_values(Person::query()->where('user_id', $this->userId())->pluck('id')->map(fn (mixed $id) => (string) $id)->all());
    }

    private function userId(): ?string
    {
        $id = $this->auth->guard()->id();

        return $id === null ? null : (string) $id;
    }
}
