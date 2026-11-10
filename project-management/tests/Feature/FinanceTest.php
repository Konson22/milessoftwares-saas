<?php

use App\Enums\BudgetVersionStatus;
use App\Enums\ExpenseStatus;
use App\Models\ApprovalPolicy;
use App\Models\AuditEvent;
use App\Models\BudgetVersion;
use App\Models\Expense;
use App\Models\ExpenseDocument;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\Project;
use App\Models\User;
use App\Support\ProjectMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Financial controls: budgets in versions (drafted, submitted, approved by
| someone else, superseded by a later approved version, never edited once
| approved); spending requested, approved against what is left of the
| approved budget and of the grant charged, then paid, by different
| people; supporting documents; nothing deleted; an approvals queue.
*/

const FINANCE_PM = ['projects.view', 'projects.manage', 'projects.finance.view', 'projects.expenses.request', 'grants.view'];
const FINANCE_CONTROLLER = ['projects.view', 'projects.finance.view', 'finance.budgets.approve', 'finance.expenses.approve', 'finance.payments.record', 'grants.view', 'grants.manage'];
const FINANCE_OFFICER = ['projects.view', 'projects.finance.view', 'finance.payments.record'];
const FINANCE_MEMBER = ['projects.view', 'projects.expenses.request'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    $this->pm = $this->member('acme', FINANCE_PM);
    $this->controller = $this->member('acme', FINANCE_CONTROLLER);
    $this->officer = $this->member('acme', FINANCE_OFFICER);
    $this->teamMember = $this->member('acme', FINANCE_MEMBER);
});

function financeUrl(string $path = ''): string
{
    return test()->organizationUrl('acme', $path);
}

function actAs(User $user): void
{
    test()->actingAs($user);
}

function financeProject(): Project
{
    actAs(test()->pm);
    test()->post(financeUrl('projects'), ['code' => 'wash', 'name' => 'WASH Juba', 'status' => 'active'])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Project::query()->where('code', 'WASH')->firstOrFail());
}

function projectUrl(Project $project, string $path = ''): string
{
    return financeUrl(rtrim("projects/{$project->id}/{$path}", '/'));
}

function version(Project $project, int $number): BudgetVersion
{
    return test()->asTenant('acme', fn () => BudgetVersion::query()->where('project_id', $project->id)->where('number', $number)->firstOrFail());
}

/**
 * Equipment 5,000 and travel 1,000, submitted by the PM, approved by the
 * controller.
 */
function approvedBudget(Project $project): BudgetVersion
{
    actAs(test()->pm);
    test()->post(projectUrl($project, 'budget/lines'), ['category' => 'equipment', 'amount' => '5000'])->assertSessionHasNoErrors();
    test()->post(projectUrl($project, 'budget/lines'), ['category' => 'travel', 'amount' => '1000'])->assertSessionHasNoErrors();
    $version = version($project, 1);
    test()->post(projectUrl($project, "budget/versions/{$version->id}/submit"))->assertSessionHasNoErrors();
    actAs(test()->controller);
    test()->post(projectUrl($project, "budget/versions/{$version->id}/approve"))->assertSessionHasNoErrors();

    return $version->fresh() ?? $version;
}

/**
 * @param  array<string, mixed>  $details
 */
function requestSpending(Project $project, array $details = []): Expense
{
    test()->post(projectUrl($project, 'expenses/requests'), [
        'category' => 'equipment', 'description' => 'Water pump', 'supplier' => 'Juba Hardware', 'amount' => '1200',
        ...$details,
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Expense::query()->latest()->orderByDesc('id')->firstOrFail());
}

function expenseFresh(Expense $expense): Expense
{
    return test()->asTenant('acme', fn () => Expense::query()->whereKey($expense->id)->firstOrFail());
}

it('versions the budget: drafted, approved by someone else, then only changed by a new version', function () {
    $project = financeProject();

    // Lines start a draft; until one is approved the budget is the draft's.
    $this->post(projectUrl($project, 'budget/lines'), ['category' => 'equipment', 'amount' => '5000'])->assertSessionHasNoErrors();
    $this->post(projectUrl($project, 'budget/lines'), ['category' => 'travel', 'amount' => '1000'])->assertSessionHasNoErrors();
    $v1 = version($project, 1);
    expect($v1->status)->toBe(BudgetVersionStatus::Draft)
        ->and($v1->total_minor)->toBe(600_000)
        ->and($project->fresh()?->budget_minor)->toBe(600_000);

    // Whoever submits does not approve; returning needs a reason.
    $this->post(projectUrl($project, "budget/versions/{$v1->id}/submit"), ['note' => 'First year'])->assertSessionHasNoErrors();
    $this->post(projectUrl($project, 'budget/lines'), ['category' => 'travel', 'amount' => '50'])->assertSessionHasErrors('budget');
    $this->post(projectUrl($project, "budget/versions/{$v1->id}/approve"))->assertForbidden();
    actAs($this->controller);
    $this->post(projectUrl($project, "budget/versions/{$v1->id}/return"))->assertSessionHasErrors('reason');
    $this->post(projectUrl($project, "budget/versions/{$v1->id}/return"), ['reason' => 'Split travel by trip'])->assertSessionHasNoErrors();
    expect(version($project, 1)->status)->toBe(BudgetVersionStatus::Draft);

    actAs($this->pm);
    $this->post(projectUrl($project, "budget/versions/{$v1->id}/submit"))->assertSessionHasNoErrors();
    // A controller who also submitted it may not approve it either.
    $both = $this->member('acme', [...FINANCE_PM, 'finance.budgets.approve']);
    actAs($both);
    $this->post(projectUrl($project, "budget/versions/{$v1->id}/return"), ['reason' => 'x'])->assertSessionHasNoErrors();
    $this->post(projectUrl($project, "budget/versions/{$v1->id}/submit"))->assertSessionHasNoErrors();
    $this->post(projectUrl($project, "budget/versions/{$v1->id}/approve"))->assertSessionHasErrors('budget');

    actAs($this->controller);
    $this->post(projectUrl($project, "budget/versions/{$v1->id}/approve"))->assertSessionHasNoErrors();
    expect(version($project, 1)->status)->toBe(BudgetVersionStatus::Approved);

    // Approved lines never change: a revision copies them into version 2.
    actAs($this->pm);
    $line = $this->asTenant('acme', fn () => $v1->lines()->firstOrFail());
    $this->delete(projectUrl($project, "budget/lines/{$line->id}"))->assertSessionHasErrors('budget');
    $this->post(projectUrl($project, 'budget/lines'), ['category' => 'materials', 'amount' => '400'])->assertSessionHasNoErrors();
    $v2 = version($project, 2);
    expect($v2->total_minor)->toBe(640_000)
        ->and($this->asTenant('acme', fn () => $v2->lines()->count()))->toBe(3)
        // The approved budget still rules until version 2 is approved.
        ->and($project->fresh()?->budget_minor)->toBe(600_000);

    $this->post(projectUrl($project, "budget/versions/{$v2->id}/submit"))->assertSessionHasNoErrors();
    actAs($this->controller);
    $this->post(projectUrl($project, "budget/versions/{$v2->id}/approve"))->assertSessionHasNoErrors();
    expect(version($project, 1)->status)->toBe(BudgetVersionStatus::Superseded)
        ->and(version($project, 2)->status)->toBe(BudgetVersionStatus::Approved)
        ->and($project->fresh()?->budget_minor)->toBe(640_000)
        ->and(AuditEvent::query()->where('action', 'budget.approved')->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'budget.returned')->count())->toBe(2);

    // A draft revision can be thrown away; an approved version cannot.
    actAs($this->pm);
    $this->post(projectUrl($project, 'budget/versions'))->assertSessionHasNoErrors();
    $v3 = version($project, 3);
    $this->delete(projectUrl($project, "budget/versions/{$v3->id}"))->assertSessionHasNoErrors();
    $this->delete(projectUrl($project, "budget/versions/{$v2->id}"))->assertSessionHasErrors('budget');
    expect($this->asTenant('acme', fn () => BudgetVersion::query()->count()))->toBe(2);
});

it('takes spending from request to approval to payment, by different people', function () {
    $project = financeProject();

    // No approved budget: a request waits, but cannot be approved.
    actAs($this->teamMember);
    $pump = requestSpending($project);
    expect($pump->status)->toBe(ExpenseStatus::Requested);
    actAs($this->controller);
    $this->post(projectUrl($project, "expenses/{$pump->id}/approve"))->assertSessionHasErrors('expense');

    approvedBudget($project);

    // The requester never approves or pays their own.
    $self = $this->member('acme', [...FINANCE_MEMBER, 'finance.expenses.approve', 'finance.payments.record']);
    actAs($self);
    $own = requestSpending($project, ['description' => 'Own pick', 'amount' => '10']);
    $this->post(projectUrl($project, "expenses/{$own->id}/approve"))->assertSessionHasErrors('expense');

    // Approval must fit what is left in the category.
    actAs($this->teamMember);
    $big = requestSpending($project, ['description' => 'Generator', 'amount' => '4500']);
    actAs($this->controller);
    $this->post(projectUrl($project, "expenses/{$pump->id}/approve"), ['note' => 'Within plan'])->assertSessionHasNoErrors();
    $this->post(projectUrl($project, "expenses/{$big->id}/approve"))->assertSessionHasErrors('expense');
    $this->post(projectUrl($project, "expenses/{$big->id}/reject"))->assertSessionHasErrors('reason');
    $this->post(projectUrl($project, "expenses/{$big->id}/reject"), ['reason' => 'Over the equipment line'])->assertSessionHasNoErrors();
    expect(expenseFresh($pump)->status)->toBe(ExpenseStatus::Approved)
        ->and(expenseFresh($big)->status)->toBe(ExpenseStatus::Rejected);

    // Committed is not spent: the project's actual cost waits for payment.
    $this->get(projectUrl($project, 'budget'))->assertInertia(fn (Assert $page) => $page
        ->where('committedMinor', 120_000)
        ->where('actualMinor', 0)
        ->where('byCategory.0.category', 'equipment')
        ->where('byCategory.0.availableMinor', 380_000));
    expect($this->asTenant('acme', fn () => ProjectMetrics::for($project)['actualMinor']))->toBe(0);

    // A revision may not take a category below what is committed.
    actAs($this->pm);
    $this->post(projectUrl($project, 'budget/versions'))->assertSessionHasNoErrors();
    $v2 = version($project, 2);
    $equipment = $this->asTenant('acme', fn () => $v2->lines()->where('category', 'equipment')->firstOrFail());
    $this->delete(projectUrl($project, "budget/lines/{$equipment->id}"))->assertSessionHasNoErrors();
    $this->post(projectUrl($project, "budget/versions/{$v2->id}/submit"))->assertSessionHasNoErrors();
    actAs($this->controller);
    $this->post(projectUrl($project, "budget/versions/{$v2->id}/approve"))->assertSessionHasErrors('budget');
    $this->post(projectUrl($project, "budget/versions/{$v2->id}/return"), ['reason' => 'Equipment is committed'])->assertSessionHasNoErrors();

    // Paid by the finance officer, with a reference.
    actAs($this->teamMember);
    $this->post(projectUrl($project, "expenses/{$pump->id}/pay"), ['paid_on' => '2026-11-04', 'reference' => 'CHQ-1'])->assertForbidden();
    actAs($this->officer);
    $this->post(projectUrl($project, "expenses/{$pump->id}/pay"), ['paid_on' => '2026-11-04'])->assertSessionHasErrors('reference');
    $this->post(projectUrl($project, "expenses/{$pump->id}/pay"), ['paid_on' => '2026-11-04', 'reference' => 'CHQ-1'])->assertSessionHasNoErrors();
    $paid = expenseFresh($pump);
    expect($paid->status)->toBe(ExpenseStatus::Paid)
        ->and($paid->payment_reference)->toBe('CHQ-1')
        ->and($paid->paid_by)->toBe($this->officer->id)
        ->and($this->asTenant('acme', fn () => ProjectMetrics::for($project)['actualMinor']))->toBe(120_000)
        ->and(AuditEvent::query()->whereIn('action', ['expense.requested', 'expense.approved', 'expense.rejected', 'expense.paid'])->count())->toBe(6);

    // Members see only their own requests, and no budget.
    $other = $this->member('acme', FINANCE_MEMBER);
    actAs($other);
    $this->get(projectUrl($project, 'budget'))->assertForbidden();
    $this->get(projectUrl($project, 'expenses'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/projects/expenses')
        ->has('expenses', 0)
        ->where('finance', false));
    actAs($this->teamMember);
    $this->get(projectUrl($project, 'expenses'))->assertInertia(fn (Assert $page) => $page->has('expenses', 2));
    $this->member('acme', ['projects.view']);
    actAs($this->member('acme', ['projects.view']));
    $this->get(projectUrl($project, 'expenses'))->assertForbidden();
    $this->post(projectUrl($project, 'expenses/requests'), ['category' => 'equipment', 'description' => 'x', 'amount' => '1'])->assertForbidden();
});

it('cancels and voids spending without deleting it', function () {
    $project = financeProject();
    approvedBudget($project);

    actAs($this->teamMember);
    $request = requestSpending($project);
    // Someone else without project management cannot cancel it.
    actAs($this->member('acme', FINANCE_MEMBER));
    $this->post(projectUrl($project, "expenses/{$request->id}/cancel"))->assertForbidden();
    actAs($this->teamMember);
    $this->post(projectUrl($project, "expenses/{$request->id}/cancel"))->assertSessionHasNoErrors();
    expect(expenseFresh($request)->status)->toBe(ExpenseStatus::Cancelled);
    $this->post(projectUrl($project, "expenses/{$request->id}/cancel"))->assertForbidden();

    // A payment is voided with a reason, and stays on record.
    $paid = requestSpending($project, ['description' => 'Fuel', 'category' => 'travel', 'amount' => '200']);
    actAs($this->controller);
    $this->post(projectUrl($project, "expenses/{$paid->id}/approve"))->assertSessionHasNoErrors();
    $this->post(projectUrl($project, "expenses/{$paid->id}/pay"), ['paid_on' => '2026-11-03', 'reference' => 'TRF-9'])->assertSessionHasNoErrors();
    actAs($this->teamMember);
    $this->post(projectUrl($project, "expenses/{$paid->id}/cancel"))->assertForbidden();
    actAs($this->officer);
    $this->post(projectUrl($project, "expenses/{$paid->id}/cancel"))->assertSessionHasErrors('reason');
    $this->post(projectUrl($project, "expenses/{$paid->id}/cancel"), ['reason' => 'Paid twice; refunded'])->assertSessionHasNoErrors();
    expect(expenseFresh($paid)->status)->toBe(ExpenseStatus::Cancelled)
        ->and(AuditEvent::query()->where('action', 'expense.voided')->exists())->toBeTrue();

    // Recorded directly by the manager (kept from before approvals), then
    // voided by them: marked direct, never deleted.
    actAs($this->pm);
    $this->post(projectUrl($project, 'expenses'), [
        'category' => 'equipment', 'description' => 'Tarpaulins', 'amount' => '300', 'spent_on' => '2026-11-02',
    ])->assertSessionHasNoErrors();
    $direct = $this->asTenant('acme', fn () => Expense::query()->where('description', 'Tarpaulins')->firstOrFail());
    expect($direct->direct)->toBeTrue()->and($direct->status)->toBe(ExpenseStatus::Paid);
    $this->post(projectUrl($project, "expenses/{$direct->id}/cancel"), ['reason' => 'Wrong project'])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => Expense::query()->count()))->toBe(3)
        ->and($this->asTenant('acme', fn () => ProjectMetrics::for($project)['actualMinor']))->toBe(0);
});

it('checks spending charged to a grant against its allocation to the project', function () {
    $project = financeProject();
    approvedBudget($project);

    actAs($this->controller);
    $this->post(financeUrl('funders'), ['name' => 'ECHO', 'kind' => 'institutional'])->assertSessionHasNoErrors();
    $funder = $this->asTenant('acme', fn () => Funder::query()->firstOrFail());
    $this->post(financeUrl('grants'), [
        'funder_id' => $funder->id, 'reference' => 'ECHO-1', 'title' => 'WASH', 'currency' => 'USD', 'amount' => '10000', 'status' => 'applied',
    ])->assertSessionHasNoErrors();
    $grant = $this->asTenant('acme', fn () => Grant::query()->where('reference', 'ECHO-1')->firstOrFail());
    $this->post(financeUrl("grants/{$grant->id}/award"), ['amount' => '10000', 'on' => '2026-11-01'])->assertSessionHasNoErrors();

    // Not funding the project yet: it cannot be charged.
    actAs($this->teamMember);
    $this->post(projectUrl($project, 'expenses/requests'), [
        'category' => 'equipment', 'description' => 'Pump', 'amount' => '100', 'grant_id' => $grant->id,
    ])->assertSessionHasErrors('grant_id');

    actAs($this->controller);
    $this->post(financeUrl("grants/{$grant->id}/allocations"), ['project_id' => $project->id, 'amount' => '1000'])->assertSessionHasNoErrors();

    actAs($this->teamMember);
    $pump = requestSpending($project, ['grant_id' => $grant->id, 'amount' => '800']);
    $tank = requestSpending($project, ['grant_id' => $grant->id, 'description' => 'Tank', 'amount' => '300']);
    actAs($this->controller);
    $this->post(projectUrl($project, "expenses/{$pump->id}/approve"))->assertSessionHasNoErrors();
    // The budget has room, the grant's allocation does not.
    $this->post(projectUrl($project, "expenses/{$tank->id}/approve"))->assertSessionHasErrors('expense');

    // What is charged holds the allocation and the grant open.
    $allocation = $this->asTenant('acme', fn () => $grant->allocations()->firstOrFail());
    $this->delete(financeUrl("grants/{$grant->id}/allocations/{$allocation->id}"))->assertSessionHasErrors('allocation');
    $this->post(financeUrl("grants/{$grant->id}/allocations"), ['project_id' => $project->id, 'amount' => '500'])->assertSessionHasErrors('amount');
    $this->post(financeUrl("grants/{$grant->id}/close"), ['on' => '2026-11-04'])->assertSessionHasErrors('grant');

    actAs($this->officer);
    $this->post(projectUrl($project, "expenses/{$pump->id}/pay"), ['paid_on' => '2026-11-04', 'reference' => 'W-1'])->assertSessionHasNoErrors();

    actAs($this->controller);
    $this->get(financeUrl("grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('allocations.0.committedMinor', 0)
        ->where('allocations.0.spentMinor', 80_000));
    $this->get(projectUrl($project, 'budget'))->assertInertia(fn (Assert $page) => $page
        ->where('funding.0.spentMinor', 80_000)
        ->where('funding.0.projectAmountMinor', 100_000));
});

it('keeps supporting documents private to those who may see the spending', function () {
    Storage::fake('local');
    $project = financeProject();
    approvedBudget($project);

    actAs($this->teamMember);
    $pump = requestSpending($project);
    $this->post(projectUrl($project, "expenses/{$pump->id}/documents"), ['document' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')])
        ->assertSessionHasErrors('document');
    $this->post(projectUrl($project, "expenses/{$pump->id}/documents"), ['document' => UploadedFile::fake()->create('quote.pdf', 120, 'application/pdf')])
        ->assertSessionHasNoErrors();
    $document = $this->asTenant('acme', fn () => ExpenseDocument::query()->firstOrFail());
    expect($document->path)->toStartWith("organizations/{$document->organization_id}/expenses/{$pump->id}/");
    Storage::disk('local')->assertExists($document->path);

    $download = projectUrl($project, "expenses/{$pump->id}/documents/{$document->id}");
    $this->get($download)->assertOk()->assertDownload('quote.pdf');
    actAs($this->member('acme', FINANCE_MEMBER));
    $this->get($download)->assertForbidden();
    $this->post(projectUrl($project, "expenses/{$pump->id}/documents"), ['document' => UploadedFile::fake()->create('mine.pdf', 5, 'application/pdf')])->assertForbidden();
    actAs($this->controller);
    $this->get($download)->assertOk();

    // Once decided, documents stay.
    $this->post(projectUrl($project, "expenses/{$pump->id}/approve"))->assertSessionHasNoErrors();
    actAs($this->teamMember);
    $this->delete($download)->assertSessionHasErrors('document');
    Storage::disk('local')->assertExists($document->path);
});

it('queues what waits on finance for those who may act on it', function () {
    $project = financeProject();
    approvedBudget($project);
    actAs($this->teamMember);
    requestSpending($project);

    $this->get(financeUrl('approvals'))->assertForbidden();

    actAs($this->controller);
    $this->get(financeUrl('approvals'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/finance/approvals')
        ->has('budgets', 0)
        ->has('requests', 1)
        ->where('requests.0.availableMinor', 500_000)
        ->has('payments', 0));

    actAs($this->officer);
    $this->get(financeUrl('approvals'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('budgets', null)
        ->where('requests', null)
        ->has('payments', 0));
});

it('keeps spending inside its project and organization', function () {
    $project = financeProject();
    actAs($this->teamMember);
    $pump = requestSpending($project);

    actAs($this->pm);
    $this->post(financeUrl('projects'), ['code' => 'edu', 'name' => 'Schools', 'status' => 'active'])->assertSessionHasNoErrors();
    $other = $this->asTenant('acme', fn () => Project::query()->where('code', 'EDU')->firstOrFail());
    actAs($this->controller);
    $this->post(projectUrl($other, "expenses/{$pump->id}/approve"))->assertNotFound();

    // Another organization's member, with every permission there.
    $this->actingAsMember('globex', owner: true);
    $this->post(test()->organizationUrl('globex', "projects/{$project->id}/expenses/{$pump->id}/approve"))->assertNotFound();
    expect(expenseFresh($pump)->status)->toBe(ExpenseStatus::Requested);
});

it('needs as many approvers as the organization sets for larger amounts, each a different person', function () {
    $project = financeProject();
    approvedBudget($project);
    $owner = $this->member('acme', owner: true);
    $second = $this->member('acme', FINANCE_CONTROLLER);

    // Only administrators set the bands.
    actAs($this->controller);
    $this->post(financeUrl('admin/approval-policies'), ['currency' => 'USD', 'min_amount' => '1000', 'approvals_required' => 2])->assertForbidden();
    actAs($owner);
    $this->post(financeUrl('admin/approval-policies'), ['currency' => 'usd', 'min_amount' => '1000', 'approvals_required' => 4])
        ->assertSessionHasErrors('approvals_required');
    $this->post(financeUrl('admin/approval-policies'), ['currency' => 'usd', 'min_amount' => '1000', 'approvals_required' => 2])
        ->assertSessionHasNoErrors();
    $this->post(financeUrl('admin/approval-policies'), ['currency' => 'USD', 'min_amount' => '1000.00', 'approvals_required' => 3])
        ->assertSessionHasErrors('min_amount');
    $this->get(financeUrl('admin/settings'))->assertInertia(fn (Assert $page) => $page
        ->has('approvalPolicies', 1)
        ->where('approvalPolicies.0.currency', 'USD')
        ->where('approvalPolicies.0.minAmountMinor', 100_000)
        ->where('approvalPolicies.0.approvalsRequired', 2));

    // Below the band: one approver, as before.
    actAs($this->pm);
    $small = requestSpending($project, ['amount' => '999.99']);
    expect(expenseFresh($small)->approvals_required)->toBe(1);

    // From the band up: two.
    $large = requestSpending($project, ['amount' => '1200']);
    expect(expenseFresh($large)->approvals_required)->toBe(2);

    actAs($this->controller);
    $this->post(projectUrl($project, "expenses/{$large->id}/approve"))->assertSessionHas('success', fn ($message) => str_contains($message, 'needs another approver'));
    expect(expenseFresh($large)->status)->toBe(ExpenseStatus::Requested);
    $this->asTenant('acme', fn () => expect(ProjectMetrics::for($project)['actualMinor'])->toBe(0));

    // The same person cannot approve twice.
    $this->post(projectUrl($project, "expenses/{$large->id}/approve"))->assertSessionHasErrors('expense');
    $this->get(financeUrl('approvals'))->assertInertia(fn (Assert $page) => $page
        ->where('requests', fn ($requests) => collect($requests)->firstWhere('id', $large->id)['approvedByMe'] === true
            && collect($requests)->firstWhere('id', $large->id)['approvalsRequired'] === 2));

    // A later change of policy does not move a request already made.
    actAs($owner);
    $band = $this->asTenant('acme', fn () => ApprovalPolicy::query()->firstOrFail());
    $this->delete(financeUrl("admin/approval-policies/{$band->id}"))->assertSessionHasNoErrors();
    expect(expenseFresh($large)->approvals_required)->toBe(2);

    actAs($second);
    $this->post(projectUrl($project, "expenses/{$large->id}/approve"))->assertSessionHas('success', 'Approved: the money is committed.');
    expect(expenseFresh($large)->status)->toBe(ExpenseStatus::Approved)
        ->and(expenseFresh($large)->decided_by)->toBe($second->id);

    $actions = AuditEvent::query()->where('subject_id', $large->id)->pluck('action')->all();
    expect($actions)->toContain('expense.approval_recorded', 'expense.approved')
        ->and(AuditEvent::query()->whereIn('action', ['approval_policy.created', 'approval_policy.removed'])->count())->toBe(2);
});

it('keeps each organization\'s approval bands to itself', function () {
    $owner = $this->member('acme', owner: true);
    actAs($owner);
    $this->post(financeUrl('admin/approval-policies'), ['currency' => 'USD', 'min_amount' => '1000', 'approvals_required' => 2])->assertSessionHasNoErrors();
    $band = $this->asTenant('acme', fn () => ApprovalPolicy::query()->firstOrFail());

    $other = $this->member('globex', owner: true);
    actAs($other);
    $this->delete($this->organizationUrl('globex', "admin/approval-policies/{$band->id}"))->assertNotFound();
    $this->get($this->organizationUrl('globex', 'admin/settings'))->assertInertia(fn (Assert $page) => $page->has('approvalPolicies', 0));
});
