<?php

use App\Actions\Tasks;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Enums\TaskPriority;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The figures behind the portfolio's charts: right, complete with empty
| periods, within this organization, and money only for those who may see it.
*/

function chartProject(string $slug, string $code, array $details = []): Project
{
    return test()->asTenant($slug, fn () => Project::query()->create([
        'code' => $code, 'name' => "Project {$code}", 'status' => Project::ACTIVE, 'currency' => 'USD',
        'created_by' => User::factory()->create()->id, ...$details,
    ]));
}

function chartTask(string $slug, Project $project, string $status, ?string $completedAt = null, ?string $parentId = null): Task
{
    return test()->asTenant($slug, function () use ($project, $status, $completedAt, $parentId) {
        $task = app(Tasks::class)->create($project, ['title' => 'A task', 'status' => $status, 'priority' => TaskPriority::Medium], User::factory()->create());

        $task->forceFill(['completed_at' => $completedAt, 'parent_id' => $parentId])->save();

        return $task;
    });
}

function chartExpense(string $slug, Project $project, int $amount, string $on, ExpenseStatus $status = ExpenseStatus::Paid): void
{
    test()->asTenant($slug, fn () => Expense::query()->create([
        'project_id' => $project->id, 'category' => CostCategory::Other, 'description' => 'Spending', 'amount_minor' => $amount,
        'spent_on' => $on, 'status' => $status, 'recorded_by' => User::factory()->create()->id,
    ]));
}

beforeEach(function () {
    // A Wednesday.
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00'));
});

it('counts tasks completed per week, with empty weeks, top-level only', function () {
    $this->actingAsMember('acme', ['projects.view']);
    $project = chartProject('acme', 'WEB');
    $parent = chartTask('acme', $project, Task::DONE, '2026-11-02 08:00:00');
    chartTask('acme', $project, Task::DONE, '2026-11-01 23:00:00');
    chartTask('acme', $project, Task::DONE, '2026-08-17 09:00:00');
    // Before the first week shown.
    chartTask('acme', $project, Task::DONE, '2026-08-16 23:00:00');
    chartTask('acme', $project, Task::DONE, '2026-11-03 09:00:00', $parent->id);

    $this->get($this->organizationUrl('acme'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/home')
        ->has('portfolio.completedPerWeek', 12)
        ->where('portfolio.completedPerWeek.0', ['start' => '2026-08-17', 'count' => 1])
        ->where('portfolio.completedPerWeek.10', ['start' => '2026-10-26', 'count' => 1])
        ->where('portfolio.completedPerWeek.11', ['start' => '2026-11-02', 'count' => 1]));
});

it('counts tasks by status on open projects, in workflow order', function () {
    $this->actingAsMember('acme', ['projects.view']);
    $open = chartProject('acme', 'WEB');
    $closed = chartProject('acme', 'OLD');
    chartTask('acme', $open, Task::TODO);
    chartTask('acme', $open, Task::TODO);
    chartTask('acme', $open, Task::REVIEW);
    chartTask('acme', $closed, Task::DONE);
    $this->asTenant('acme', fn () => $closed->forceFill(['status' => Project::COMPLETED])->save());

    $this->get($this->organizationUrl('acme'))->assertInertia(fn (Assert $page) => $page->where('portfolio.taskStatus', [
        ['status' => Task::TODO, 'count' => 2],
        ['status' => Task::IN_PROGRESS, 'count' => 0],
        ['status' => Task::REVIEW, 'count' => 1],
        ['status' => Task::DONE, 'count' => 0],
    ]));
});

it('totals paid expenses per month and ranks budget use, for those who may see money', function () {
    $this->actingAsMember('acme', ['projects.view', 'projects.finance.view']);
    $big = chartProject('acme', 'BIG', ['budget_minor' => 100_000]);
    $small = chartProject('acme', 'SML', ['budget_minor' => 1_000]);
    chartProject('acme', 'NON');
    chartExpense('acme', $big, 20_000, '2026-11-01');
    chartExpense('acme', $big, 5_000, '2026-10-31');
    chartExpense('acme', $small, 1_500, '2026-10-02');
    // Not paid, or too old.
    chartExpense('acme', $big, 7_000, '2026-11-01', ExpenseStatus::Approved);
    chartExpense('acme', $big, 9_000, '2025-11-30');

    $this->get($this->organizationUrl('acme'))->assertInertia(fn (Assert $page) => $page
        ->has('portfolio.paidPerMonth', 12)
        ->where('portfolio.paidPerMonth.0', ['month' => '2025-12', 'amountMinor' => 0])
        ->where('portfolio.paidPerMonth.10', ['month' => '2026-10', 'amountMinor' => 6_500])
        ->where('portfolio.paidPerMonth.11', ['month' => '2026-11', 'amountMinor' => 20_000])
        ->has('portfolio.budgets', 2)
        ->where('portfolio.budgets.0.code', 'SML')
        ->where('portfolio.budgets.0.percent', 150)
        ->where('portfolio.budgets.1.code', 'BIG')
        ->where('portfolio.budgets.1.percent', 34));
});

it('shows no money without the finance permission', function () {
    $this->actingAsMember('acme', ['projects.view']);
    $project = chartProject('acme', 'WEB', ['budget_minor' => 100_000]);
    chartExpense('acme', $project, 20_000, '2026-11-01');

    $this->get($this->organizationUrl('acme'))->assertInertia(fn (Assert $page) => $page
        ->where('portfolio.paidPerMonth', null)
        ->where('portfolio.budgets', null)
        ->where('portfolio.budgetMinor', null));
});

it('shows no charts without permission to view projects', function () {
    $this->actingAsMember('acme', ['grants.view']);
    chartTask('acme', chartProject('acme', 'WEB'), Task::DONE, '2026-11-02 08:00:00');

    $this->get($this->organizationUrl('acme'))->assertInertia(fn (Assert $page) => $page->where('portfolio', null));
});

it('keeps each organization\'s figures to itself', function () {
    $this->actingAsMember('acme', ['projects.view', 'projects.finance.view']);
    $theirs = chartProject('other', 'THR', ['budget_minor' => 100_000]);
    chartTask('other', $theirs, Task::DONE, '2026-11-02 08:00:00');
    chartExpense('other', $theirs, 20_000, '2026-11-01');

    $this->get($this->organizationUrl('acme'))->assertInertia(fn (Assert $page) => $page
        ->where('portfolio.completedPerWeek.11.count', 0)
        ->where('portfolio.paidPerMonth.11.amountMinor', 0)
        ->where('portfolio.taskStatus.3.count', 0)
        ->where('portfolio.budgets', []));
});
