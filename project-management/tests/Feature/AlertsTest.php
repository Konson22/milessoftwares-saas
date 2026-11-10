<?php

use App\Actions\Alerts;
use App\Actions\Budgets;
use App\Actions\Spending;
use App\Actions\Tasks;
use App\Enums\CostCategory;
use App\Enums\GrantReportStatus;
use App\Enums\GrantStatus;
use App\Models\Expense;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\GrantDisbursement;
use App\Models\GrantReport;
use App\Models\OrganizationMembership;
use App\Models\Person;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AlertDigest;
use App\Notifications\AlertEmail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| Approvals and decisions are told now, in the app and by email. Reminders are
| given once by the daily run, and what stays unread goes in one digest.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    Notification::fake();
    $this->requester = $this->member('acme', ['projects.view']);
    $this->approver = $this->member('acme', ['projects.view', 'finance.expenses.approve']);
    $this->payer = $this->member('acme', ['projects.view', 'finance.payments.record']);
    $this->manager = $this->member('acme', ['projects.view', 'grants.manage']);
    $this->bystander = $this->member('acme', ['projects.view']);
});

function alertProject(): Project
{
    return test()->asTenant('acme', function () {
        $project = new Project(['code' => 'WASH', 'name' => 'WASH Juba', 'currency' => 'USD']);
        $project->forceFill(['status' => 'active', 'created_by' => test()->requester->id])->save();

        return $project;
    });
}

function alertBudgetApproved(Project $project): void
{
    test()->asTenant('acme', function () use ($project) {
        $drafter = test()->requester;
        $approver = test()->approver;
        Budgets::revise($project, $drafter);
        Budgets::addLine($project, CostCategory::Travel, 'Flights', 500_000, $drafter);
        $version = Budgets::working($project);
        Budgets::submit($project, $version, null, $drafter);
        Budgets::approve($project, $version, $approver);
        UserNotification::query()->delete();
    });
}

function spendingRequest(Project $project): Expense
{
    return Spending::request($project, ['category' => CostCategory::Travel, 'description' => 'Flights to Juba', 'supplier' => null, 'amount_minor' => 12_500, 'grant_id' => null], test()->requester);
}

/** @return list<string> */
function alertTitles(User $user): array
{
    return test()->asTenant('acme', fn () => UserNotification::query()->where('user_id', $user->id)->orderBy('created_at')->pluck('title')->all());
}

function grantOverdue(): Grant
{
    return test()->asTenant('acme', function () {
        $funder = Funder::query()->create(['name' => 'Nile Fund', 'kind' => 'foundation']);
        $grant = new Grant(['funder_id' => $funder->id, 'reference' => 'NF/1', 'title' => 'Water', 'currency' => 'USD', 'amount_minor' => 100]);
        $grant->forceFill(['status' => GrantStatus::Active, 'created_by' => test()->manager->id])->save();

        return $grant;
    });
}

it('tells approvers of a request, in the app and by email, not the requester or others', function () {
    $this->asTenant('acme', fn () => spendingRequest(alertProject()));

    expect(alertTitles($this->approver))->toHaveCount(1)
        ->and(alertTitles($this->approver)[0])->toContain('asks you to approve spending on WASH')
        ->and(alertTitles($this->requester))->toBe([])
        ->and(alertTitles($this->bystander))->toBe([]);

    Notification::assertSentTo($this->approver, AlertEmail::class, fn (AlertEmail $mail) => $mail->url === test()->organizationUrl('acme', 'approvals'));
    Notification::assertNotSentTo($this->bystander, AlertEmail::class);
});

it('tells the requester of the decision, and payers once it is approved', function () {
    $project = alertProject();
    alertBudgetApproved($project);

    $this->asTenant('acme', function () use ($project) {
        $expense = spendingRequest($project);
        Spending::approve($expense, null, $this->approver);
        Spending::pay($expense->refresh(), CarbonImmutable::parse('2026-11-04'), 'CHQ-1', $this->payer);
    });

    expect(alertTitles($this->requester))->toHaveCount(2)
        ->and(implode('|', alertTitles($this->requester)))->toContain('approved your spending')->toContain('paid your spending')
        ->and(alertTitles($this->payer))->toHaveCount(1)
        ->and(alertTitles($this->payer)[0])->toContain('ready to pay');
});

it('tells the requester why it was rejected', function () {
    $this->asTenant('acme', function () {
        Spending::reject(spendingRequest(alertProject()), 'Over the line', $this->approver);
    });

    expect(alertTitles($this->requester)[0])->toContain('rejected')->toContain('Over the line');
});

it('tells budget approvers of a submission and the submitter of the decision', function () {
    $budgetApprover = $this->member('acme', ['projects.view', 'finance.budgets.approve']);

    $this->asTenant('acme', function () use ($budgetApprover) {
        $project = alertProject();
        Budgets::revise($project, $this->requester);
        Budgets::addLine($project, CostCategory::Travel, 'Flights', 500, $this->requester);
        $version = Budgets::working($project);
        Budgets::submit($project, $version, null, $this->requester);
        Budgets::returnToDraft($project, $version, 'Too little detail', $budgetApprover);
    });

    expect(alertTitles($budgetApprover)[0])->toContain('approve the budget of WASH')
        ->and(alertTitles($this->requester)[0])->toContain('returned the budget')->toContain('Too little detail');
});

it('sends nothing when the change is refused', function () {
    $this->asTenant('acme', function () {
        expect(fn () => Spending::request(alertProject(), ['category' => CostCategory::Labour, 'description' => 'Hours', 'supplier' => null, 'amount_minor' => 1, 'grant_id' => null], $this->requester))
            ->toThrow(ValidationException::class);
    });

    expect(alertTitles($this->approver))->toBe([]);
    Notification::assertNothingSent();
});

it('keeps the notification but sends no email to someone who turned email off', function () {
    $this->actingAs($this->approver)->post($this->organizationUrl('acme', 'account/email-alerts'), ['email_alerts' => false])->assertRedirect();
    expect($this->approver->refresh()->email_alerts)->toBeFalse();

    $this->asTenant('acme', fn () => spendingRequest(alertProject()));

    expect(alertTitles($this->approver))->toHaveCount(1);
    Notification::assertNotSentTo($this->approver, AlertEmail::class);
});

it('leaves out holders who are suspended, or whose permission is gone', function () {
    $this->asTenant('acme', function () {
        OrganizationMembership::query()->where('user_id', $this->approver->id)->update(['status' => OrganizationMembership::SUSPENDED]);
        expect(Alerts::holders('finance.expenses.approve'))->toHaveCount(0)
            ->and(Alerts::holders('finance.payments.record')->pluck('id')->all())->toBe([$this->payer->id]);
    });
});

it('opens a notification on its page, and only for its owner', function () {
    $this->asTenant('acme', fn () => spendingRequest(alertProject()));
    $notification = $this->asTenant('acme', fn () => UserNotification::query()->where('user_id', $this->approver->id)->sole());

    $this->actingAs($this->bystander)->post($this->organizationUrl('acme', "notifications/{$notification->id}/open"))->assertNotFound();
    $response = $this->actingAs($this->approver)->post($this->organizationUrl('acme', "notifications/{$notification->id}/open"));
    expect($response->headers->get('Location'))->toEndWith('/approvals')
        ->and($notification->refresh()->read_at)->not->toBeNull();
});

it('reminds of an overdue task once, then digests it once', function () {
    $this->asTenant('acme', function () {
        $project = alertProject();
        $person = new Person(['name' => $this->bystander->name]);
        $person->forceFill(['user_id' => $this->bystander->id, 'active' => true])->save();
        (new Tasks)->create($project, ['title' => 'Drill the borehole', 'priority' => 'medium', 'due_on' => '2026-11-01', 'assignee_id' => $person->id], $this->requester);
        // Being given the task is its own notification: this is about the reminder.
        UserNotification::query()->delete();
    });

    Artisan::call('alerts:remind');
    Artisan::call('alerts:remind');

    expect(alertTitles($this->bystander))->toBe(['WASH-1 is overdue']);
    Notification::assertSentToTimes($this->bystander, AlertDigest::class, 1);
    Notification::assertSentTo($this->bystander, AlertDigest::class, fn (AlertDigest $mail) => $mail->total === 1 && $mail->titles === ['WASH-1 is overdue']);
});

it('reminds grant managers of reports and payments, without closed grants or finished work', function () {
    $grant = grantOverdue();
    $this->asTenant('acme', function () use ($grant) {
        GrantReport::query()->create(['grant_id' => $grant->id, 'title' => 'Q3 report', 'kind' => 'financial', 'due_on' => '2026-11-08', 'status' => GrantReportStatus::Due]);
        GrantReport::query()->create(['grant_id' => $grant->id, 'title' => 'Q2 report', 'kind' => 'financial', 'due_on' => '2026-10-01', 'status' => GrantReportStatus::Due]);
        GrantReport::query()->create(['grant_id' => $grant->id, 'title' => 'Far off', 'kind' => 'financial', 'due_on' => '2027-02-01', 'status' => GrantReportStatus::Due]);
        GrantReport::query()->create(['grant_id' => $grant->id, 'title' => 'Done', 'kind' => 'financial', 'due_on' => '2026-09-01', 'status' => GrantReportStatus::Accepted]);
        GrantDisbursement::query()->create(['grant_id' => $grant->id, 'due_on' => '2026-10-15', 'amount_minor' => 50]);
    });

    Artisan::call('alerts:remind');
    Artisan::call('alerts:remind');

    expect(alertTitles($this->manager))->toHaveCount(3)
        ->and(implode('|', alertTitles($this->manager)))->toContain('is due on 8 Nov 2026')->toContain('report to the funder of NF/1 is overdue')->toContain('payment from the funder of NF/1 is overdue')
        ->and(alertTitles($this->bystander))->toBe([]);
    Notification::assertSentToTimes($this->manager, AlertDigest::class, 1);
});

it('leaves what was already emailed, read, or is old out of the digest', function () {
    $this->asTenant('acme', function () {
        spendingRequest(alertProject());
        foreach ([['Read', now(), now()->subDay()], ['Old', null, now()->subDays(30)]] as [$title, $readAt, $at]) {
            UserNotification::query()->create(['user_id' => $this->approver->id, 'kind' => UserNotification::DECISION, 'title' => $title, 'url' => '/', 'read_at' => $readAt, 'created_at' => $at]);
        }
    });

    Artisan::call('alerts:remind');

    Notification::assertNotSentTo($this->approver, AlertDigest::class);
});

it('keeps each organization\'s reminders in that organization', function () {
    grantOverdue();
    $this->asTenant('acme', fn () => GrantDisbursement::query()->create(['grant_id' => Grant::query()->sole()->id, 'due_on' => '2026-10-15', 'amount_minor' => 50]));
    $outsider = $this->member('other', ['projects.view', 'grants.manage']);

    Artisan::call('alerts:remind');

    expect($this->asTenant('other', fn () => UserNotification::query()->where('user_id', $outsider->id)->count()))->toBe(0);
    Notification::assertNotSentTo($outsider, AlertDigest::class);
});
