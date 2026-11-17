<?php

use App\Collaboration\Models\Participant;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Phase 5, shared tasks: a task of the owner's project shared with the
| organizations taking part, or given to one of them. The owner keeps the
| record and is the only one to mark it done.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00'));
    Notification::fake();
    Storage::fake('local');
});

const PARTNER_WORKER = ['projects.view', 'projects.partners.manage', 'projects.tasks.manage'];

/**
 * global-fund owns WELL and brings in water-now (implementer, may work on
 * tasks) and audit-co (donor-like observer, view only). Ends signed in as
 * the owner.
 *
 * @return array{project: Project, implementer: Participant, observer: Participant}
 */
function sharedTaskSetup(): array
{
    $project = collabProject('global-fund', 'WELL');
    $implementer = acceptProjectInvitation(inviteToProject($project, 'water-now', 'implementer', ['view', 'activities.manage'], owner: 'global-fund'), 'water-now');
    $observer = acceptProjectInvitation(inviteToProject($project, 'audit-co', 'donor', ['view'], owner: 'global-fund'), 'audit-co');
    test()->actingAsMember('global-fund', [], owner: true);

    return ['project' => $project, 'implementer' => $implementer, 'observer' => $observer];
}

function sharedTaskOf(Project $project, string $title): Task
{
    test()->post(test()->organizationUrl('global-fund', "projects/{$project->id}/tasks"), ['title' => $title, 'priority' => 'medium'])->assertSessionHasNoErrors();

    return test()->asTenant('global-fund', fn () => Task::query()->where('title', $title)->firstOrFail());
}

function ownerTaskUrl(Project $project, Task $task, string $suffix = ''): string
{
    return test()->organizationUrl('global-fund', "projects/{$project->id}/shared-tasks/{$task->id}{$suffix}");
}

function partnerTaskUrl(Participant $participant, string $slug, ?Task $task = null, string $suffix = ''): string
{
    return test()->organizationUrl($slug, "shared-projects/{$participant->id}/shared-tasks".($task ? "/{$task->id}" : '').$suffix);
}

it('keeps tasks internal until the owner shares them or gives one to a partner', function () {
    ['project' => $project, 'implementer' => $implementer, 'observer' => $observer] = sharedTaskSetup();
    $internal = sharedTaskOf($project, 'Internal budget work');
    $drill = sharedTaskOf($project, 'Drill the wells');
    $plan = sharedTaskOf($project, 'Agree the work plan');

    $this->put(ownerTaskUrl($project, $drill, '/sharing'), ['shared' => '0', 'participant_id' => $implementer->id])->assertSessionHasNoErrors();
    $this->put(ownerTaskUrl($project, $plan, '/sharing'), ['shared' => '1'])->assertSessionHasNoErrors();

    // The implementer: its task and the shared one, never the internal one.
    $this->actingAsMember('water-now', PARTNER_WORKER);
    $this->get(partnerTaskUrl($implementer, 'water-now'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/collaboration/tasks')
        ->has('tasks', 2)
        ->where('tasks.0.title', 'Drill the wells')->where('tasks.0.givenTo', 'Water Now')
        ->where('tasks.1.title', 'Agree the work plan')->where('tasks.1.shared', true));
    $this->get(partnerTaskUrl($implementer, 'water-now', $internal))->assertNotFound();

    // The observer: only the shared one, and it cannot work on it.
    $this->actingAsMember('audit-co', PARTNER_WORKER);
    $this->get(partnerTaskUrl($observer, 'audit-co'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 1));
    $this->get(partnerTaskUrl($observer, 'audit-co', $drill))->assertNotFound();
    $this->get(partnerTaskUrl($observer, 'audit-co', $plan))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canWork', false));
    $this->post(partnerTaskUrl($observer, 'audit-co', $plan, '/comments'), ['body' => 'Hi'])->assertForbidden();

    // An organization not taking part sees nothing.
    $this->actingAsMember('other-org', PARTNER_WORKER);
    $this->get(partnerTaskUrl($implementer, 'other-org'))->assertNotFound();
});

it('lets the implementer comment, attach and send its task for review; only the owner marks it done', function () {
    ['project' => $project, 'implementer' => $implementer] = sharedTaskSetup();
    $drill = sharedTaskOf($project, 'Drill the wells');
    $this->put(ownerTaskUrl($project, $drill, '/sharing'), ['shared' => '0', 'participant_id' => $implementer->id])->assertSessionHasNoErrors();

    $this->actingAsMember('water-now', PARTNER_WORKER);
    $this->post(partnerTaskUrl($implementer, 'water-now', $drill, '/progress'), ['status' => 'in_progress'])->assertSessionHasNoErrors();
    $this->post(partnerTaskUrl($implementer, 'water-now', $drill, '/comments'), ['body' => 'Three of four wells drilled.'])->assertSessionHasNoErrors();
    $this->post(partnerTaskUrl($implementer, 'water-now', $drill, '/attachments'), ['file' => UploadedFile::fake()->create('photos.pdf', 120, 'application/pdf')])->assertSessionHasNoErrors();
    // Done is the owner's call.
    $this->post(partnerTaskUrl($implementer, 'water-now', $drill, '/progress'), ['status' => 'done'])->assertSessionHasErrors('status');
    $this->flushSession();
    $this->post(partnerTaskUrl($implementer, 'water-now', $drill, '/progress'), ['status' => 'review'])->assertSessionHasNoErrors();

    $task = $this->asTenant('global-fund', fn () => $drill->fresh());
    expect($task?->status)->toBe(Task::REVIEW)->and($task?->organization_id)->toBe($this->organization('global-fund')->id);

    // The owner sees who wrote what, and accepts.
    $this->actingAsMember('global-fund', [], owner: true);
    $this->get(ownerTaskUrl($project, $drill))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('comments.0.for', 'Water Now')->where('attachments.0.for', 'Water Now')->where('canReview', true));
    $this->post(ownerTaskUrl($project, $drill, '/review'), ['decision' => 'return'])->assertSessionHasErrors('note');
    $this->flushSession();
    $this->post(ownerTaskUrl($project, $drill, '/review'), ['decision' => 'return', 'note' => 'Add the water test results.'])->assertSessionHasNoErrors();
    expect($this->asTenant('global-fund', fn () => $drill->fresh()?->status))->toBe(Task::IN_PROGRESS);

    $this->actingAsMember('water-now', PARTNER_WORKER);
    $this->post(partnerTaskUrl($implementer, 'water-now', $drill, '/progress'), ['status' => 'review'])->assertSessionHasNoErrors();
    $this->actingAsMember('global-fund', [], owner: true);
    $this->post(ownerTaskUrl($project, $drill, '/review'), ['decision' => 'accept'])->assertSessionHasNoErrors();
    expect($this->asTenant('global-fund', fn () => $drill->fresh()?->status))->toBe(Task::DONE)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', 'task.partner_work_accepted')->count())->toBe(1)
        // Recorded on both sides.
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', 'task.partner_progress')->distinct()->count('organization_id'))->toBe(2);
});

it('needs the work capability and the member permission to work on a task', function () {
    $project = collabProject('global-fund', 'WELL');
    $viewOnly = acceptProjectInvitation(inviteToProject($project, 'water-now', 'implementer', ['view'], owner: 'global-fund'), 'water-now');
    $this->actingAsMember('global-fund', [], owner: true);
    $drill = sharedTaskOf($project, 'Drill the wells');
    $this->put(ownerTaskUrl($project, $drill, '/sharing'), ['shared' => '0', 'participant_id' => $viewOnly->id])->assertSessionHasNoErrors();

    // Given the task, but its participation may not work on tasks: reads only.
    $this->actingAsMember('water-now', PARTNER_WORKER);
    $this->get(partnerTaskUrl($viewOnly, 'water-now', $drill))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canWork', false));
    $this->post(partnerTaskUrl($viewOnly, 'water-now', $drill, '/progress'), ['status' => 'review'])->assertForbidden();

    // Nor may the owner's people share without managing tasks.
    $this->actingAsMember('global-fund', ['projects.view']);
    $this->put(ownerTaskUrl($project, $drill, '/sharing'), ['shared' => '1'])->assertForbidden();
});

it('takes access away when the participation ends, and keeps the work', function () {
    ['project' => $project, 'implementer' => $implementer] = sharedTaskSetup();
    $drill = sharedTaskOf($project, 'Drill the wells');
    $this->put(ownerTaskUrl($project, $drill, '/sharing'), ['shared' => '0', 'participant_id' => $implementer->id])->assertSessionHasNoErrors();
    $this->actingAsMember('water-now', PARTNER_WORKER);
    $this->post(partnerTaskUrl($implementer, 'water-now', $drill, '/comments'), ['body' => 'Started.'])->assertSessionHasNoErrors();

    $this->actingAsMember('global-fund', [], owner: true);
    $this->post($this->organizationUrl('global-fund', "projects/{$project->id}/collaboration/participants/{$implementer->id}/status"), ['status' => 'revoked'])->assertSessionHasNoErrors();

    // Gone for them at once...
    $this->actingAsMember('water-now', PARTNER_WORKER);
    $this->get(partnerTaskUrl($implementer, 'water-now', $drill))->assertForbidden();
    $this->post(partnerTaskUrl($implementer, 'water-now', $drill, '/comments'), ['body' => 'Still here?'])->assertForbidden();

    // ...kept for the owner, with what is left open counted, and no new task can go to them.
    $this->actingAsMember('global-fund', [], owner: true);
    $this->get(ownerTaskUrl($project, $drill))->assertOk()->assertInertia(fn (Assert $page) => $page->has('comments', 1)->where('task.givenTo', 'Water Now'));
    $ended = AuditEvent::query()->withoutGlobalScopes()->where('action', 'collaboration.status_changed')->where('organization_id', $this->organization('global-fund')->id)->latest('id')->first();
    expect($ended?->properties['open_tasks_left'] ?? null)->toBe(1);
    $other = sharedTaskOf($project, 'Fence the site');
    $this->put(ownerTaskUrl($project, $other, '/sharing'), ['shared' => '0', 'participant_id' => $implementer->id])->assertSessionHasErrors('participant_id');
});

it('offers the owner sharing on the task page only once the project has partners', function () {
    $project = collabProject('global-fund', 'SOLO');
    $this->actingAsMember('global-fund', [], owner: true);
    $task = sharedTaskOf($project, 'Alone');
    $this->get($this->organizationUrl('global-fund', "projects/{$project->id}/tasks/{$task->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sharing', null));

    $implementer = acceptProjectInvitation(inviteToProject($project, 'water-now', 'implementer', ['view', 'activities.manage'], owner: 'global-fund'), 'water-now');
    $this->actingAsMember('global-fund', [], owner: true);
    $this->get($this->organizationUrl('global-fund', "projects/{$project->id}/tasks/{$task->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sharing.shared', false)->where('sharing.participants.0.value', $implementer->id)->where('sharing.canChange', true));
});
