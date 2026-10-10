<?php

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Support\AuthenticationEventRecorder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

it('shows sign-in events and the audit log to security auditors', function () {
    $user = User::factory()->create();
    app(AuthenticationEventRecorder::class)->record('login_failed', $user);
    app(AuditLogger::class)->record('organization.created');

    $this->actingAs($this->staffWith(['security_auditor'])->user)
        ->get($this->adminUrl('/security?event=login_failed'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/security/index')
            ->has('authenticationEvents.data', 1)
            ->where('authenticationEvents.data.0.user', $user->email)
            ->where('auditLogs.data', fn ($logs) => collect($logs)->pluck('action')->contains('organization.created')));
});

it('forbids the security log to other staff', function () {
    $this->actingAs($this->staffWith(['support_agent'])->user)->get($this->adminUrl('/security'))->assertForbidden();
});

it('reports an intact audit chain and records the check', function () {
    app(AuditLogger::class)->record('organization.created');
    app(AuditLogger::class)->record('organization.slug_changed');

    $this->actingAs($this->staffWith(['security_auditor'])->user)
        ->post($this->adminUrl('/security/verify-chain'))
        ->assertSessionHas('chain_check', fn (array $check) => $check['intact'] === true);

    expect(AuditLog::query()->latest('sequence')->value('action'))->toBe('audit.chain_verified');
});

it('reports where the audit chain was tampered with', function () {
    app(AuditLogger::class)->record('organization.created');
    $tampered = app(AuditLogger::class)->record('organization.slug_changed');
    app(AuditLogger::class)->record('role.assigned');

    // The application role cannot UPDATE audit rows (Phase 10 grants); in
    // tests the trigger is bypassed as the table owner to simulate tampering.
    DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER USER');
    DB::table('audit_logs')->where('sequence', $tampered->sequence)->update(['action' => 'organization.deleted']);
    DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER USER');

    $this->actingAs($this->staffWith(['security_auditor'])->user)
        ->post($this->adminUrl('/security/verify-chain'))
        ->assertSessionHas('chain_check', fn (array $check) => $check['intact'] === false && $check['firstBrokenSequence'] === $tampered->sequence);
});
