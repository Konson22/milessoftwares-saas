<?php

use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Stabilization milestone: project-level access inside an organization, and
| the project money M&E pages may show.
|
| A project is open to the organization (the default, and what every
| existing project keeps) or to its team only. Team-only projects are seen
| by the owner, holders of projects.all, its manager, whoever set it up and
| its team; the restriction reaches the project's records too (tasks,
| expenses, attachments...), and external participants keep the access
| their participation gives.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00'));
    Notification::fake();
});

/**
 * Someone in acme with these permissions, with a Person record (so they
 * can be put on a team).
 *
 * @param  list<string>  $permissions
 * @return array{user: User, person: Person}
 */
function paMember(array $permissions, string $name = 'Field Officer'): array
{
    $user = User::factory()->create(['name' => $name]);
    test()->actingAsMember('acme', $permissions, user: $user);
    $person = test()->asTenant('acme', fn () => tap(tap(new Person)->forceFill(['name' => $name, 'user_id' => $user->id]))->save());

    return ['user' => $user, 'person' => $person];
}

/**
 * A team-only project and a task on it, set up by the owner.
 *
 * @return array{project: Project, task: Task}
 */
function paTeamProject(): array
{
    $project = collabProject();
    test()->put(test()->organizationUrl('acme', "projects/{$project->id}"), [
        'code' => 'WASH', 'name' => 'Clean water', 'visibility' => 'team',
    ])->assertSessionHasNoErrors();
    $task = test()->asTenant('acme', fn () => Task::query()->where('project_id', $project->id)->first())
        ?? test()->asTenant('acme', function () use ($project) {
            test()->post(test()->organizationUrl('acme', "projects/{$project->id}/tasks"), ['title' => 'Drill the first well', 'priority' => 'medium'])->assertSessionHasNoErrors();

            return Task::query()->where('project_id', $project->id)->firstOrFail();
        });

    return ['project' => $project->refresh(), 'task' => $task];
}

it('keeps every existing and new project open to the organization unless made team-only', function () {
    $project = collabProject();
    expect($project->visibility)->toBe(Project::VISIBLE_TO_ORGANIZATION);

    paMember(['projects.view']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}"))->assertOk();
});

it('shows a team-only project, and its records, to its team and to those who see every project only', function () {
    ['project' => $project, 'task' => $task] = paTeamProject();
    $projectUrl = $this->organizationUrl('acme', "projects/{$project->id}");
    $taskUrl = $this->organizationUrl('acme', "projects/{$project->id}/tasks/{$task->id}");

    // Someone who may see projects, but is not on this one's team.
    ['user' => $user, 'person' => $person] = paMember(['projects.view', 'projects.tasks.manage']);
    $this->get($projectUrl)->assertNotFound();
    $this->get($taskUrl)->assertNotFound();
    $this->get($this->organizationUrl('acme', 'projects'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('projects', fn ($projects) => collect($projects['data'] ?? $projects)->pluck('id')->doesntContain($project->id)));
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/reports"))->assertNotFound();

    // On the team: in.
    $member = $this->asTenant('acme', fn () => ProjectMember::query()->create(['project_id' => $project->id, 'person_id' => $person->id]));
    $this->get($projectUrl)->assertOk();
    $this->get($taskUrl)->assertOk();

    // Taken off the team: out again, at once.
    $this->asTenant('acme', fn () => ProjectMember::query()->whereKey($member->id)->delete());
    $this->get($projectUrl)->assertNotFound();

    // Portfolio managers (projects.all, in the admin, manager, controller and
    // finance officer roles) and the owner see it.
    $this->actingAsMember('acme', rolePermissions('manager'));
    $this->get($projectUrl)->assertOk();
    $this->get($taskUrl)->assertOk();
    $this->actingAsMember('acme', [], owner: true);
    $this->get($projectUrl)->assertOk();
});

it('lets nobody make a project team-only and so shut themselves out of it', function () {
    $project = collabProject();
    paMember(['projects.view', 'projects.manage']);

    $this->put($this->organizationUrl('acme', "projects/{$project->id}"), ['code' => 'WASH', 'name' => 'Clean water', 'visibility' => 'team'])
        ->assertSessionHasErrors('visibility');
    expect($project->refresh()->visibility)->toBe(Project::VISIBLE_TO_ORGANIZATION);
});

it('keeps external participants on a team-only project through their participation', function () {
    $project = collabProject();
    $participant = acceptProjectInvitation(inviteToProject($project, 'lead-ngo', 'lead_implementer', ['view']), 'lead-ngo');
    $this->actingAsMember('acme', [], owner: true);
    $this->put($this->organizationUrl('acme', "projects/{$project->id}"), ['code' => 'WASH', 'name' => 'Clean water', 'visibility' => 'team'])->assertSessionHasNoErrors();

    $this->actingAsMember('lead-ngo', COLLAB_MANAGER);
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$participant->id}"))->assertOk();
});
