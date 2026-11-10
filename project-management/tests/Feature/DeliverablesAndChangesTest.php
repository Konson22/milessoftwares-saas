<?php

use App\Enums\BudgetVersionStatus;
use App\Models\AuditEvent;
use App\Models\BudgetVersion;
use App\Models\ChangeRequest;
use App\Models\Deliverable;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

const DC_LEAD = ['projects.view', 'projects.manage', 'projects.tasks.manage', 'projects.finance.view'];
const DC_APPROVER = ['projects.view', 'projects.changes.approve', 'projects.finance.view'];

beforeEach(function () {
    $this->organization('acme');
    $this->lead = $this->actingAsMember('acme', DC_LEAD);
});

function dcUrl(string $path = '', string $slug = 'acme'): string
{
    return test()->organizationUrl($slug, $path);
}

/**
 * @param  array<string, mixed>  $details
 */
function dcProject(string $code = 'wash', array $details = []): Project
{
    test()->post(dcUrl('projects'), ['code' => $code, 'name' => "Project {$code}", ...$details])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Project::query()->where('code', mb_strtoupper($code))->sole());
}

function dcDeliverable(Project $project, string $name = 'Borehole report'): Deliverable
{
    test()->post(dcUrl("projects/{$project->id}/deliverables"), ['name' => $name])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Deliverable::query()->where('name', $name)->sole());
}

/**
 * @param  array<string, mixed>  $details
 */
function dcChange(Project $project, array $details = []): ChangeRequest
{
    test()->post(dcUrl("projects/{$project->id}/changes"), ['title' => 'More wells', 'description' => 'Demand grew', ...$details])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => ChangeRequest::query()->where('project_id', $project->id)->orderByDesc('number')->first());
}

function dcApproveAs(ChangeRequest $change, ?string $note = null): void
{
    test()->actingAsMember('acme', DC_APPROVER);
    test()->post(dcUrl("projects/{$change->project_id}/changes/{$change->id}/approve"), ['note' => $note])->assertSessionHasNoErrors();
}

// Deliverables

it('takes a deliverable from planned to accepted, and records who did what', function () {
    $project = dcProject();
    $deliverable = dcDeliverable($project);
    $url = "projects/{$project->id}/deliverables/{$deliverable->id}";

    $this->post(dcUrl("{$url}/accept"))->assertSessionHasErrors('deliverable');

    $this->post(dcUrl("{$url}/deliver"), ['note' => 'In the shared folder'])->assertSessionHasNoErrors();
    $this->post(dcUrl("{$url}/accept"))->assertSessionHasNoErrors();

    $fresh = $this->asTenant('acme', fn () => $deliverable->fresh());
    expect($fresh->status)->toBe(Deliverable::ACCEPTED)
        ->and($fresh->delivered_by)->toBe($this->lead->id)
        ->and($fresh->accepted_by)->toBe($this->lead->id)
        ->and($fresh->delivery_note)->toBe('In the shared folder')
        ->and(AuditEvent::query()->where('action', 'deliverable.accepted')->count())->toBe(1);

    // Final: an accepted deliverable no longer changes.
    $this->put(dcUrl($url), ['name' => 'Changed'])->assertSessionHasErrors('deliverable');
    $this->post(dcUrl("{$url}/cancel"))->assertSessionHasErrors('deliverable');

    $this->get(dcUrl("projects/{$project->id}/deliverables"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/projects/deliverables')
        ->where('deliverables.0.status', Deliverable::ACCEPTED)
        ->where('deliverables.0.deliveredBy', $this->lead->name));
});

it('sends a delivered deliverable back with a reason, and it can be delivered again', function () {
    $project = dcProject();
    $deliverable = dcDeliverable($project);
    $url = "projects/{$project->id}/deliverables/{$deliverable->id}";
    $this->post(dcUrl("{$url}/deliver"))->assertSessionHasNoErrors();

    $this->post(dcUrl("{$url}/send-back"), ['note' => ''])->assertSessionHasErrors('note');
    $this->post(dcUrl("{$url}/send-back"), ['note' => 'Missing the water tests'])->assertSessionHasNoErrors();

    $fresh = $this->asTenant('acme', fn () => $deliverable->fresh());
    expect($fresh->status)->toBe(Deliverable::PLANNED)
        ->and($fresh->returned_reason)->toBe('Missing the water tests')
        ->and($fresh->delivered_at)->toBeNull();

    $this->post(dcUrl("{$url}/deliver"))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => $deliverable->fresh()->returned_reason))->toBeNull();
});

it('leaves a closed project\'s deliverables as they are', function () {
    $project = dcProject();
    $deliverable = dcDeliverable($project);
    $this->post(dcUrl("projects/{$project->id}/status"), ['status' => Project::COMPLETED])->assertSessionHasNoErrors();

    $this->post(dcUrl("projects/{$project->id}/deliverables"), ['name' => 'Late'])->assertSessionHasErrors('deliverable');
    $this->post(dcUrl("projects/{$project->id}/deliverables/{$deliverable->id}/deliver"))->assertSessionHasErrors('deliverable');
});

it('takes a milestone only from the same project, and survives its removal', function () {
    $project = dcProject();
    $other = dcProject('sani');
    $this->post(dcUrl("projects/{$other->id}/milestones"), ['name' => 'Elsewhere', 'due_on' => '2027-01-01'])->assertSessionHasNoErrors();
    $this->post(dcUrl("projects/{$project->id}/milestones"), ['name' => 'Here', 'due_on' => '2027-01-01'])->assertSessionHasNoErrors();
    [$elsewhere, $here] = $this->asTenant('acme', fn () => [$other->milestones()->sole(), $project->milestones()->sole()]);

    $this->post(dcUrl("projects/{$project->id}/deliverables"), ['name' => 'Bad', 'milestone_id' => $elsewhere->id])->assertSessionHasErrors('milestone_id');
    $this->post(dcUrl("projects/{$project->id}/deliverables"), ['name' => 'Good', 'milestone_id' => $here->id])->assertSessionHasNoErrors();

    $this->delete(dcUrl("projects/{$project->id}/milestones/{$here->id}"))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => Deliverable::query()->where('name', 'Good')->sole()->milestone_id))->toBeNull();
});

it('checks permission on each step of a deliverable', function () {
    $project = dcProject();
    $deliverable = dcDeliverable($project);
    $url = "projects/{$project->id}/deliverables/{$deliverable->id}";

    $this->actingAsMember('acme', ['projects.view', 'projects.tasks.manage']);
    $this->post(dcUrl("projects/{$project->id}/deliverables"), ['name' => 'No'])->assertForbidden();
    $this->post(dcUrl("{$url}/deliver"))->assertSessionHasNoErrors();
    $this->post(dcUrl("{$url}/accept"))->assertForbidden();

    $this->actingAsMember('acme', ['projects.view']);
    $this->get(dcUrl("projects/{$project->id}/deliverables"))->assertOk();
    $this->post(dcUrl("{$url}/deliver"))->assertForbidden();

    $this->actingAsMember('acme', []);
    $this->get(dcUrl("projects/{$project->id}/deliverables"))->assertForbidden();
});

it('finds a deliverable only through its own project', function () {
    $project = dcProject();
    $other = dcProject('sani');
    $deliverable = dcDeliverable($project);

    $this->post(dcUrl("projects/{$other->id}/deliverables/{$deliverable->id}/deliver"))->assertNotFound();
});

// Change requests

it('raises a request, numbers it per project, and tells those who decide', function () {
    $project = dcProject();
    $approver = $this->member('acme', DC_APPROVER);

    $first = dcChange($project);
    $second = dcChange($project, ['title' => 'Another']);
    $elsewhere = dcChange(dcProject('sani'));

    expect($first->number)->toBe(1)->and($second->number)->toBe(2)->and($elsewhere->number)->toBe(1);
    expect(UserNotification::query()->where('user_id', $approver->id)->where('kind', UserNotification::APPROVAL)->count())->toBe(3);

    $this->get(dcUrl("projects/{$project->id}/changes"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/projects/changes')
        ->has('changes', 2)
        ->where('changes.0.reference', 'WASH-CR-2')
        ->where('changes.0.canDecide', false)
        ->where('changes.0.canWithdraw', true));
});

it('never lets whoever raised a request decide it, even an owner', function () {
    $project = dcProject();
    $this->actingAsMember('acme', [...DC_LEAD, 'projects.changes.approve']);
    $change = dcChange($project);

    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/approve"))->assertSessionHasErrors('change');
    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/reject"), ['note' => 'No'])->assertSessionHasErrors('change');

    $owner = $this->actingAsMember('acme', owner: true);
    $own = dcChange($project);
    $this->post(dcUrl("projects/{$project->id}/changes/{$own->id}/approve"))->assertSessionHasErrors('change');
    expect($own->raised_by)->toBe($owner->id);
});

it('needs the separate permission to decide', function () {
    $project = dcProject();
    $change = dcChange($project);

    // Managing a project is not enough.
    $this->actingAsMember('acme', DC_LEAD);
    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/approve"))->assertForbidden();
    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/reject"), ['note' => 'No'])->assertForbidden();

    $this->actingAsMember('acme', ['projects.view']);
    $this->post(dcUrl("projects/{$project->id}/changes"), ['title' => 'T', 'description' => 'D'])->assertForbidden();
});

it('moves the due date when approved, and keeps what it was', function () {
    $project = dcProject('wash', ['due_on' => '2027-01-01']);
    $change = dcChange($project, ['proposed_due_on' => '2027-06-30']);
    expect($this->asTenant('acme', fn () => $project->fresh()->due_on->toDateString()))->toBe('2027-01-01');

    dcApproveAs($change, 'Agreed with the funder');

    $fresh = $this->asTenant('acme', fn () => $change->fresh());
    expect($this->asTenant('acme', fn () => $project->fresh()->due_on->toDateString()))->toBe('2027-06-30')
        ->and($fresh->status)->toBe(ChangeRequest::APPROVED)
        ->and($fresh->previous_due_on->toDateString())->toBe('2027-01-01')
        ->and($fresh->decision_note)->toBe('Agreed with the funder')
        ->and(AuditEvent::query()->where('action', 'change_request.approved')->count())->toBe(1)
        ->and(UserNotification::query()->where('user_id', $this->lead->id)->where('kind', UserNotification::DECISION)->count())->toBe(1);

    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/approve"))->assertSessionHasErrors('change');
});

it('refuses a new due date before the project starts', function () {
    $project = dcProject('wash', ['start_on' => '2027-01-01']);

    $this->post(dcUrl("projects/{$project->id}/changes"), ['title' => 'T', 'description' => 'D', 'proposed_due_on' => '2026-01-01'])->assertSessionHasErrors('proposed_due_on');
});

it('needs a reason to reject, and changes nothing', function () {
    $project = dcProject('wash', ['due_on' => '2027-01-01']);
    $change = dcChange($project, ['proposed_due_on' => '2027-06-30', 'budget_direction' => 'increase', 'budget_change' => '500']);

    $this->actingAsMember('acme', DC_APPROVER);
    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/reject"), ['note' => ''])->assertSessionHasErrors('note');
    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/reject"), ['note' => 'Out of scope'])->assertSessionHasNoErrors();

    $this->asTenant('acme', function () use ($project, $change) {
        expect($project->fresh()->due_on->toDateString())->toBe('2027-01-01')
            ->and($project->budgetVersions()->count())->toBe(0)
            ->and($change->fresh()->status)->toBe(ChangeRequest::REJECTED);
    });
});

it('lets the raiser or a manager withdraw, and nobody else', function () {
    $project = dcProject();
    $this->actingAsMember('acme', ['projects.view', 'projects.tasks.manage']);
    $change = dcChange($project);
    $url = "projects/{$project->id}/changes/{$change->id}/withdraw";

    $this->actingAsMember('acme', ['projects.view', 'projects.tasks.manage']);
    $this->post(dcUrl($url))->assertForbidden();

    $this->actingAs($this->lead);
    $this->post(dcUrl($url))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => $change->fresh()->status))->toBe(ChangeRequest::WITHDRAWN);

    $this->post(dcUrl($url))->assertSessionHasErrors('change');
});

it('refuses raising or approving on a closed project', function () {
    $project = dcProject();
    $change = dcChange($project);
    $this->post(dcUrl("projects/{$project->id}/status"), ['status' => Project::COMPLETED])->assertSessionHasNoErrors();

    $this->post(dcUrl("projects/{$project->id}/changes"), ['title' => 'T', 'description' => 'D'])->assertSessionHasErrors('change');

    $this->actingAsMember('acme', DC_APPROVER);
    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/approve"))->assertSessionHasErrors('change');
    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/reject"), ['note' => 'Too late'])->assertSessionHasNoErrors();
});

// Change requests and the budget

function dcApprovedBudget(Project $project): BudgetVersion
{
    $lead = auth()->user();
    test()->post(dcUrl("projects/{$project->id}/budget/lines"), ['category' => 'equipment', 'amount' => '1000'])->assertSessionHasNoErrors();
    $version = test()->asTenant('acme', fn () => $project->budgetVersions()->sole());
    test()->post(dcUrl("projects/{$project->id}/budget/versions/{$version->id}/submit"))->assertSessionHasNoErrors();
    test()->actingAsMember('acme', ['projects.view', 'projects.finance.view', 'finance.budgets.approve']);
    test()->post(dcUrl("projects/{$project->id}/budget/versions/{$version->id}/approve"))->assertSessionHasNoErrors();
    /** @var User $lead */
    test()->actingAs($lead);

    return $version;
}

it('starts a draft on approval, and leaves the budget to its own steps', function () {
    $project = dcProject();
    $approved = dcApprovedBudget($project);
    $change = dcChange($project, ['budget_direction' => 'increase', 'budget_change' => '500']);
    expect($change->budget_change_minor)->toBe(50000);

    dcApproveAs($change);

    $this->asTenant('acme', function () use ($project, $approved, $change) {
        $draft = BudgetVersion::query()->find($change->fresh()->budget_version_id);

        expect($draft->status)->toBe(BudgetVersionStatus::Draft)
            ->and($draft->number)->toBe(2)
            ->and($draft->total_minor)->toBe(100000)
            // What spending is checked against has not moved.
            ->and($approved->fresh()->status)->toBe(BudgetVersionStatus::Approved)
            ->and($approved->fresh()->total_minor)->toBe(100000)
            ->and($project->fresh()->budget_minor)->toBe(100000);
    });

    $this->get(dcUrl("projects/{$project->id}/changes"))->assertInertia(fn (Assert $page) => $page
        ->where('changes.0.budgetChangeMinor', 50000)
        ->where('changes.0.budgetVersion.number', 2)
        ->where('changes.0.budgetVersion.status', 'draft'));
});

it('records a decrease as negative', function () {
    $project = dcProject();

    expect(dcChange($project, ['budget_direction' => 'decrease', 'budget_change' => '25.50'])->budget_change_minor)->toBe(-2550);

    $this->post(dcUrl("projects/{$project->id}/changes"), ['title' => 'T', 'description' => 'D', 'budget_direction' => 'increase', 'budget_change' => '0'])->assertSessionHasErrors('budget_change');
});

it('waits while a budget version awaits approval, and changes nothing', function () {
    $project = dcProject('wash', ['due_on' => '2027-01-01']);
    $this->post(dcUrl("projects/{$project->id}/budget/lines"), ['category' => 'equipment', 'amount' => '1000'])->assertSessionHasNoErrors();
    $version = $this->asTenant('acme', fn () => $project->budgetVersions()->sole());
    $this->post(dcUrl("projects/{$project->id}/budget/versions/{$version->id}/submit"))->assertSessionHasNoErrors();
    $change = dcChange($project, ['proposed_due_on' => '2027-06-30', 'budget_direction' => 'increase', 'budget_change' => '500']);

    $this->actingAsMember('acme', DC_APPROVER);
    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/approve"))->assertSessionHasErrors('budget');

    $this->asTenant('acme', function () use ($project, $change) {
        expect($change->fresh()->status)->toBe(ChangeRequest::SUBMITTED)
            ->and($project->fresh()->due_on->toDateString())->toBe('2027-01-01');
    });
});

it('offers a new draft once the one it started is discarded', function () {
    $project = dcProject();
    dcApprovedBudget($project);
    $change = dcChange($project, ['budget_direction' => 'increase', 'budget_change' => '500']);
    $restart = "projects/{$project->id}/changes/{$change->id}/budget";

    $this->post(dcUrl($restart))->assertSessionHasErrors('change');
    dcApproveAs($change);
    $this->post(dcUrl($restart))->assertForbidden();

    $this->actingAs($this->lead);
    $this->post(dcUrl($restart))->assertSessionHasErrors('change');
    $draft = $this->asTenant('acme', fn () => $change->fresh()->budget_version_id);
    $this->delete(dcUrl("projects/{$project->id}/budget/versions/{$draft}"))->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => $change->fresh()->budget_version_id))->toBeNull();
    $this->get(dcUrl("projects/{$project->id}/changes"))->assertInertia(fn (Assert $page) => $page
        ->where('changes.0.canRestartBudget', true));

    $this->post(dcUrl($restart))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => $change->fresh()->budget_version_id))->not->toBeNull();
});

it('keeps budget changes from those who may not see the budget', function () {
    $project = dcProject();
    $change = dcChange($project, ['budget_direction' => 'increase', 'budget_change' => '500']);
    $plain = dcChange($project);

    $this->actingAsMember('acme', ['projects.view', 'projects.tasks.manage', 'projects.changes.approve']);

    $this->get(dcUrl("projects/{$project->id}/changes"))->assertInertia(fn (Assert $page) => $page
        ->where('changes.1.asksBudget', true)
        ->where('changes.1.budgetChangeMinor', null)
        ->where('changes.1.canDecide', false)
        ->where('changes.0.canDecide', true));

    $this->post(dcUrl("projects/{$project->id}/changes"), ['title' => 'T', 'description' => 'D', 'budget_direction' => 'increase', 'budget_change' => '5'])->assertSessionHasErrors('budget_change');
    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/approve"))->assertSessionHasErrors('change');
    $this->post(dcUrl("projects/{$project->id}/changes/{$plain->id}/approve"))->assertSessionHasNoErrors();
});

// Between organizations

it('keeps another organization out', function () {
    $project = dcProject();
    $deliverable = dcDeliverable($project);
    $change = dcChange($project);

    $this->organization('other');
    $this->actingAsMember('other', [...DC_LEAD, 'projects.changes.approve']);

    $this->get(dcUrl("projects/{$project->id}/deliverables", 'other'))->assertNotFound();
    $this->get(dcUrl("projects/{$project->id}/changes", 'other'))->assertNotFound();
    $this->post(dcUrl("projects/{$project->id}/deliverables/{$deliverable->id}/deliver", 'other'))->assertNotFound();
    $this->post(dcUrl("projects/{$project->id}/changes/{$change->id}/approve", 'other'))->assertNotFound();
});

it('refuses another organization\'s project in the database', function () {
    $project = dcProject();
    $change = dcChange($project);
    $deliverable = dcDeliverable($project);

    $this->organization('other');
    $this->actingAsMember('other', DC_LEAD);
    $this->post(dcUrl('projects', 'other'), ['code' => 'THEIRS', 'name' => 'Theirs'])->assertSessionHasNoErrors();
    $theirs = $this->asTenant('other', fn () => Project::query()->sole());

    expect(fn () => DB::transaction(fn () => DB::table('change_requests')->where('id', $change->id)->update(['project_id' => $theirs->id])))
        ->toThrow(QueryException::class, 'change_requests_project_id_tenant_fk');
    expect(fn () => DB::transaction(fn () => DB::table('deliverables')->where('id', $deliverable->id)->update(['project_id' => $theirs->id])))
        ->toThrow(QueryException::class, 'deliverables_project_id_tenant_fk');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL enforces this');
