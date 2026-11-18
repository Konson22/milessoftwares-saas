<?php

use App\Modules\Applications\Models\Application;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Authorization\Actions\AssignmentDenied;
use App\Modules\Authorization\Actions\SetApplicationAccess;
use App\Modules\Authorization\Support\Scope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IntegrationFixtures;

uses(IntegrationFixtures::class);

beforeEach(function () {
    $this->prepareIntegrationFixtures();
    app(TenantContext::class)->set($this->organization);

    $this->cashier = $this->member();
    $this->grant($this->cashier, 'retail', 'cashier', Scope::branch($this->juba->id));
    app(TenantContext::class)->clear();
});

function accessFor(string $subject): TestResponse
{
    return test()->withToken(test()->serviceToken())->getJson(test()->apiUrl('organizations/'.test()->organization->id."/members/{$subject}/effective-access"));
}

function setAccess(mixed $actor, mixed $member, string $status): void
{
    app(TenantContext::class)->run(test()->organization, fn () => app(SetApplicationAccess::class)->handle($actor, $member, test()->retail, $status, 'Left the company'));
}

it('stops a member in one application without touching their roles, and lets them back in', function () {
    accessFor($this->cashier->user_id)->assertJsonPath('allowed', true)->assertJsonPath('membership.application_access', 'active');

    setAccess($this->owner, $this->cashier, 'suspended');

    accessFor($this->cashier->user_id)
        ->assertJsonPath('allowed', false)
        ->assertJsonPath('membership.status', 'active')
        ->assertJsonPath('membership.application_access', 'suspended')
        ->assertJsonPath('roles', []);
    expect(DB::table('role_assignments')->where('membership_id', $this->cashier->id)->whereNull('revoked_at')->count())->toBe(1);

    setAccess($this->owner, $this->cashier, 'active');
    accessFor($this->cashier->user_id)->assertJsonPath('allowed', true)->assertJsonPath('roles.0.role', 'cashier');

    $log = AuditLog::query()->where('action', 'application_access.changed')->orderBy('occurred_at')->get();
    expect($log)->toHaveCount(2)
        ->and($log->first()->context['from'])->toBe('active')
        ->and($log->first()->context['to'])->toBe('suspended')
        ->and($log->first()->context['reason'])->toBe('Left the company');
});

it('keeps a disabled member out when another role is given', function () {
    setAccess($this->owner, $this->cashier, 'disabled');
    app(TenantContext::class)->run($this->organization, fn () => $this->grant($this->cashier, 'retail', 'auditor', Scope::organization()));

    accessFor($this->cashier->user_id)->assertJsonPath('allowed', false)->assertJsonPath('membership.application_access', 'disabled');
});

it('lets only organization-wide access managers change it, never for themselves, the owner or someone more senior', function () {
    $manager = $this->member();
    $supervisor = $this->member();
    app(TenantContext::class)->run($this->organization, function () use ($manager, $supervisor) {
        $this->grant($manager, 'platform', 'access_administrator', Scope::branch($this->juba->id));
        $this->grant($manager, 'retail', 'payments_clerk', Scope::branch($this->juba->id));
        $this->grant($supervisor, 'retail', 'payments_approver', Scope::organization());
    });

    $denied = function ($actor, $member, string $code) {
        try {
            setAccess($actor, $member, 'disabled');
        } catch (AssignmentDenied $e) {
            expect($e->reason)->toBe($code);

            return;
        }
        test()->fail("Expected {$code}.");
    };

    // Managing access in one branch is not enough.
    $denied($manager, $this->cashier, 'no_access_manage');

    app(TenantContext::class)->run($this->organization, fn () => $this->grant($manager, 'platform', 'access_administrator', Scope::organization()));
    $denied($manager, $manager, 'self');
    $denied($manager, $this->owner, 'owner');
    $denied($manager, $supervisor, 'sensitivity_exceeded');

    setAccess($manager, $this->cashier, 'disabled');
    accessFor($this->cashier->user_id)->assertJsonPath('allowed', false);
});

it('changes sign-in from the access page, and refuses another workspace member', function () {
    $this->actingAs($this->owner->user);
    $url = fn (string $path) => 'http://'.$this->organization->primarySlug->slug.'.'.config('platform.root_domain').'/'.$path;

    $this->put($url('manage/access/application'), ['membership_id' => $this->cashier->id, 'application' => 'retail', 'status' => 'suspended'])
        ->assertSessionHasNoErrors();
    expect(DB::table('application_access_grants')->where('membership_id', $this->cashier->id)->value('status'))->toBe('suspended');

    $this->get($url('manage/access'))->assertInertia(fn ($page) => $page
        ->where('members', fn ($members) => collect($members)->firstWhere('id', $this->cashier->id)['applicationAccess'] === ['retail' => 'suspended']));

    $this->put($url('manage/access/application'), ['membership_id' => (string) Str::uuid(), 'application' => 'retail', 'status' => 'disabled'])
        ->assertSessionHasErrors('membership_id');
    $this->put($url('manage/access/application'), ['membership_id' => $this->owner->id, 'application' => 'retail', 'status' => 'disabled'])
        ->assertSessionHasErrors('status');
});

it('lists the application people for the product, with roles, scopes and sign-in state', function () {
    setAccess($this->owner, $this->cashier, 'suspended');

    $this->withToken($this->serviceToken())
        ->getJson($this->apiUrl('organizations/'.$this->organization->id.'/application-members'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.membership_id', $this->cashier->id)
        ->assertJsonPath('data.0.application_access', 'suspended')
        ->assertJsonPath('data.0.roles.0.role', 'cashier')
        ->assertJsonPath('data.0.roles.0.scope_type', 'branch')
        ->assertJsonPath('data.0.roles.0.branch_id', $this->juba->id);

    expect(Application::query()->where('code', 'retail')->exists())->toBeTrue();
});
