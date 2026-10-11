<?php

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->logger = app(AuditLogger::class);
});

it('chains each record to the previous one', function () {
    $first = $this->logger->record('organization.created', context: ['b' => 2, 'a' => 1]);
    $second = $this->logger->record('organization.updated');

    expect($first->prev_hash)->toBeNull()
        ->and($second->prev_hash)->toBe($first->hash)
        ->and($this->logger->verifyChain())->toBeNull();
});

it('records the actor, organization and subject', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $log = $this->logger->record('organization.suspended', $org, ['reason' => 'billing'], $user, $org->id);

    expect($log->fresh())
        ->actor_type->toBe('user')
        ->actor_id->toBe($user->id)
        ->organization_id->toBe($org->id)
        ->subject_id->toBe($org->id)
        ->context->toBe(['reason' => 'billing']);
});

it('rejects updates to audit records', function () {
    $log = $this->logger->record('user.login');

    DB::table('audit_logs')->where('sequence', $log->sequence)->update(['action' => 'tampered']);
})->throws(QueryException::class, 'append-only');

it('rejects deletion of audit records', function () {
    $this->logger->record('user.login');

    DB::table('audit_logs')->delete();
})->throws(QueryException::class, 'append-only');

it('rejects truncation of audit records', function () {
    DB::statement('TRUNCATE audit_logs');
})->throws(QueryException::class, 'append-only');

it('allows deletion only inside an explicit retention purge window', function () {
    $this->logger->record('user.login');

    DB::transaction(function () {
        DB::statement("SET LOCAL miles.audit_purge = 'on'");
        DB::table('audit_logs')->delete();
    });

    expect(AuditLog::query()->count())->toBe(0);
});

it('detects a tampered record when verifying the chain', function () {
    $this->logger->record('a');
    $tampered = $this->logger->record('b');
    $this->logger->record('c');

    // Simulate tampering by someone who bypassed the trigger.
    DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_append_only');
    DB::table('audit_logs')->where('sequence', $tampered->sequence)->update(['action' => 'forged']);
    DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_append_only');

    expect($this->logger->verifyChain())->toBe($tampered->sequence);
});
