<?php

use App\Enums\ExpenseStatus;
use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\Project;
use Inertia\Testing\AssertableInertia as Assert;

const CM_ALL = ['projects.view', 'projects.manage', 'projects.finance.view', 'projects.expenses.request', 'finance.budgets.approve', 'finance.expenses.approve', 'finance.payments.record', 'finance.commitments.view'];

beforeEach(function () {
    $this->organization('acme');
    $this->requester = $this->actingAsMember('acme', CM_ALL);
    $this->decider = $this->member('acme', CM_ALL);
});

function cmUrl(string $path = ''): string
{
    return test()->organizationUrl('acme', $path);
}

function cmProject(string $code = 'wash', string $budget = '1000', ?string $currency = null): Project
{
    test()->actingAs(test()->requester);
    test()->post(cmUrl('projects'), ['code' => $code, 'name' => "Project {$code}"])->assertSessionHasNoErrors();
    $project = test()->asTenant('acme', fn () => Project::query()->where('code', strtoupper($code))->sole());
    if ($currency !== null) {
        test()->asTenant('acme', fn () => $project->forceFill(['currency' => $currency])->save());
    }
    test()->post(cmUrl("projects/{$project->id}/budget/lines"), ['category' => 'materials', 'amount' => $budget])->assertSessionHasNoErrors();
    $version = test()->asTenant('acme', fn () => $project->budgetVersions()->sole());
    test()->post(cmUrl("projects/{$project->id}/budget/versions/{$version->id}/submit"))->assertSessionHasNoErrors();
    test()->actingAs(test()->decider);
    test()->post(cmUrl("projects/{$project->id}/budget/versions/{$version->id}/approve"))->assertSessionHasNoErrors();
    test()->actingAs(test()->requester);

    return $project->fresh() ?? $project;
}

function cmRequest(Project $project, string $amount = '100'): Expense
{
    test()->actingAs(test()->requester);
    test()->post(cmUrl("projects/{$project->id}/expenses/requests"), ['category' => 'materials', 'description' => 'Pipes', 'supplier' => 'Acme', 'amount' => $amount])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Expense::query()->latest('created_at')->orderByDesc('id')->where('project_id', $project->id)->where('status', ExpenseStatus::Requested)->firstOrFail());
}

function cmCommit(Project $project, string $amount = '100'): Expense
{
    $expense = cmRequest($project, $amount);
    test()->actingAs(test()->decider);
    test()->post(cmUrl("projects/{$project->id}/expenses/{$expense->id}/approve"))->assertSessionHasNoErrors();

    return $expense;
}

function cmPay(Project $project, Expense $expense): void
{
    test()->actingAs(test()->decider);
    test()->post(cmUrl("projects/{$project->id}/expenses/{$expense->id}/pay"), ['paid_on' => now()->toDateString(), 'reference' => 'TX1'])->assertSessionHasNoErrors();
}

it('lists what is approved and unpaid, and nothing else', function () {
    $project = cmProject();
    cmRequest($project, '5');
    $paid = cmCommit($project, '20');
    cmPay($project, $paid);
    $open = cmCommit($project, '100');

    $this->actingAs($this->decider);
    $this->get(cmUrl('commitments'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/finance/commitments')
        ->has('commitments', 1)
        ->where('commitments.0.id', $open->id)
        ->where('commitments.0.ageDays', 0)
        ->where('byCurrency.0.totalMinor', 10000)
        ->where('projects.0.budgetMinor', 100000)
        ->where('projects.0.committedMinor', 10000)
        ->where('projects.0.actualMinor', 2000)
        ->where('projects.0.leftMinor', 88000));
});

it('never adds currencies together', function () {
    cmCommit(cmProject('wash'), '100');
    cmCommit(cmProject('food', '1000', 'EUR'), '30');

    $this->actingAs($this->decider);
    $this->get(cmUrl('commitments'))->assertInertia(fn (Assert $page) => $page
        ->has('byCurrency', 2)
        ->where('byCurrency.0.currency', 'EUR')
        ->where('byCurrency.0.totalMinor', 3000)
        ->where('byCurrency.1.currency', 'USD')
        ->where('byCurrency.1.totalMinor', 10000));
});

it('shows how long money has been committed, and one project at a time', function () {
    $wash = cmProject('wash');
    $food = cmProject('food');
    $old = cmCommit($wash, '10');
    $older = cmCommit($wash, '20');
    $oldest = cmCommit($food, '30');
    $this->asTenant('acme', function () use ($old, $older, $oldest) {
        $old->refresh()->forceFill(['decided_at' => now()->subDays(45)])->save();
        $older->refresh()->forceFill(['decided_at' => now()->subDays(75)])->save();
        $oldest->refresh()->forceFill(['decided_at' => now()->subDays(120)])->save();
    });

    $this->actingAs($this->decider);
    $this->get(cmUrl('commitments'))->assertInertia(fn (Assert $page) => $page
        ->where('byCurrency.0.buckets.0.amountMinor', 0)
        ->where('byCurrency.0.buckets.1.amountMinor', 1000)
        ->where('byCurrency.0.buckets.2.amountMinor', 2000)
        ->where('byCurrency.0.buckets.3.amountMinor', 3000)
        ->where('commitments.0.ageDays', 120));

    $this->get(cmUrl("commitments?project={$food->id}"))->assertInertia(fn (Assert $page) => $page
        ->has('commitments', 1)
        ->where('commitments.0.amountMinor', 3000)
        ->where('byCurrency.0.totalMinor', 6000)
        ->has('projects', 2));
});

it('needs its permission, and keeps another organization out', function () {
    $project = cmProject();
    cmCommit($project);

    $this->actingAsMember('acme', ['projects.view']);
    $this->get(cmUrl('commitments'))->assertForbidden();

    $this->organization('other');
    $this->actingAsMember('other', ['finance.commitments.view']);
    $this->get($this->organizationUrl('other', 'commitments'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('commitments', 0)
        ->has('byCurrency', 0));
});

it('gives budget back only when a commitment is released with a reason', function () {
    $project = cmProject('wash', '100');
    $expense = cmCommit($project, '100');

    // The whole budget is held.
    $next = cmRequest($project, '1');
    $this->actingAs($this->decider);
    $this->post(cmUrl("projects/{$project->id}/expenses/{$next->id}/approve"))->assertSessionHasErrors('expense');

    $this->post(cmUrl("projects/{$project->id}/expenses/{$expense->id}/cancel"), ['reason' => ''])->assertSessionHasErrors('reason');
    expect($this->asTenant('acme', fn () => $expense->fresh()->status))->toBe(ExpenseStatus::Approved);

    $this->post(cmUrl("projects/{$project->id}/expenses/{$expense->id}/cancel"), ['reason' => 'Supplier fell through'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => $expense->fresh()->status))->toBe(ExpenseStatus::Cancelled)
        ->and(AuditEvent::query()->where('action', 'expense.cancelled')->count())->toBe(1);

    $this->post(cmUrl("projects/{$project->id}/expenses/{$next->id}/approve"))->assertSessionHasNoErrors();
});

it('keeps a request cancellable without a reason', function () {
    $project = cmProject();
    $expense = cmRequest($project);

    $this->post(cmUrl("projects/{$project->id}/expenses/{$expense->id}/cancel"))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => $expense->fresh()->status))->toBe(ExpenseStatus::Cancelled);
});

it('does not close a project over spending still open', function () {
    $project = cmProject();
    $requested = cmRequest($project);

    $this->actingAs($this->requester);
    $status = cmUrl("projects/{$project->id}/status");
    $this->post($status, ['status' => Project::COMPLETED])->assertSessionHasErrors('status');
    $this->post($status, ['status' => Project::CANCELLED])->assertSessionHasErrors('status');
    $this->post($status, ['status' => Project::ON_HOLD])->assertSessionHasNoErrors();
    $this->post($status, ['status' => Project::ACTIVE])->assertSessionHasNoErrors();

    $this->actingAs($this->decider);
    $this->post(cmUrl("projects/{$project->id}/expenses/{$requested->id}/approve"))->assertSessionHasNoErrors();
    $this->actingAs($this->requester);
    $this->post($status, ['status' => Project::COMPLETED])->assertSessionHasErrors('status');

    cmPay($project, $requested);
    $this->actingAs($this->requester);
    $this->post($status, ['status' => Project::COMPLETED])->assertSessionHasNoErrors();
});

it('tells Finance nothing of a commitment, only of the payment', function () {
    $project = cmProject();
    $expense = cmCommit($project);

    expect($this->financeEvents())->toBe([]);

    cmPay($project, $expense);
    expect(array_column($this->financeEvents(), 'type'))->toBe(['finance.project_expense.paid']);
});
