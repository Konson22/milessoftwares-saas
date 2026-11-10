<?php

use App\Enums\BudgetVersionStatus;
use App\Enums\ExpenseStatus;
use App\Enums\GrantStatus;
use App\Models\BudgetVersion;
use App\Models\Expense;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;

/*
| The demo data (php artisan projects:demo <workspace>): made through the
| app's own rules, once, and every page that shows it opens.
*/

beforeEach(function () {
    $this->owner = $this->member('asaas', owner: true);
    $this->artisan('projects:demo', ['slug' => 'asaas'])->assertSuccessful();
});

it('seeds projects, grants and spending at every stage, once', function () {
    $this->artisan('projects:demo', ['slug' => 'asaas'])->assertSuccessful();

    $this->asTenant('asaas', function () {
        expect(Project::query()->orderBy('code')->pluck('status', 'code')->all())->toBe([
            'CHOL' => Project::COMPLETED,
            'EDU' => Project::ACTIVE,
            'FSL' => Project::PLANNING,
            'WASH' => Project::ACTIVE,
        ]);

        expect(Grant::query()->get()->map(fn (Grant $grant) => $grant->status->value)->unique()->values()->all())->toEqualCanonicalizing(array_column(GrantStatus::cases(), 'value'));
        expect(Expense::query()->get()->map(fn (Expense $expense) => $expense->status->value)->unique()->values()->all())->toEqualCanonicalizing(array_column(ExpenseStatus::cases(), 'value'));

        $wash = Project::query()->where('code', 'WASH')->sole();
        expect($wash->budget_minor)->toBe(150_000_00)
            ->and($wash->tasks()->topLevel()->count())->toBe(17)
            ->and($wash->tasks()->whereNotNull('parent_id')->count())->toBe(3)
            ->and($wash->tasks()->whereNull('list_status_id')->count())->toBe(0)
            ->and(Task::query()->where('status', Task::DONE)->count())->toBeGreaterThan(0)
            ->and(TimeEntry::query()->count())->toBeGreaterThan(0);

        $fsl = Project::query()->where('code', 'FSL')->sole();
        expect(BudgetVersion::query()->where('project_id', $fsl->id)->sole()->status)->toBe(BudgetVersionStatus::Submitted);

        // The grant's exchange rate: EUR 85,000 at 1.08.
        expect(Project::query()->where('code', 'EDU')->sole()->grantAllocations()->sole()->project_amount_minor)->toBe(91_800_00);
    });
});

it('opens every page of the demo data for the owner', function () {
    $this->actingAs($this->owner);

    $projects = $this->asTenant('asaas', fn () => Project::query()->pluck('id'));
    $grants = $this->asTenant('asaas', fn () => Grant::query()->pluck('id'));

    $pages = ['', 'my-work', 'projects', 'people', 'funders', 'grants', 'approvals'];
    foreach ($grants as $id) {
        $pages[] = "grants/{$id}";
    }
    foreach ($projects as $id) {
        foreach (['', '/board', '/tasks', '/timeline', '/time', '/budget', '/expenses', '/risks'] as $part) {
            $pages[] = "projects/{$id}{$part}";
        }
    }

    foreach ($pages as $page) {
        $response = $this->get($this->organizationUrl('asaas', $page));
        expect($response->status())->toBe(200, "{$page} answered {$response->status()}");
    }
});

it('puts the demo team in the workspace with their own roles, and the owner stays the owner', function () {
    $organization = Organization::query()->where('slug', 'asaas')->sole();
    $peter = User::findByEmail('peter@asaas.test');

    expect($peter)->not->toBeNull()
        ->and($organization->memberships()->where('user_id', $peter->id)->value('roles'))->not->toBe([])
        ->and($organization->memberships()->where('is_owner', true)->pluck('user_id')->all())->toBe([$this->owner->id]);
});
