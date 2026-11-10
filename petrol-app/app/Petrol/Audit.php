<?php

namespace App\Petrol;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only history for stock and money. The platform keeps its own
 * audit log; this one is the station's, in the station database.
 */
class Audit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(User $actor, string $action, Model $subject, ?array $before, ?array $after, ?string $branchId): void
    {
        AuditLog::query()->create([
            'branch_id' => $branchId,
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => request()->ip(),
        ]);
    }
}
