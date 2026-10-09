<?php

namespace App\Modules\Audit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only. The database rejects UPDATE/DELETE; write through AuditLogger.
 *
 * @property int $sequence
 * @property string $id
 * @property Carbon $occurred_at
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $organization_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $context
 * @property string|null $prev_hash
 * @property string $hash
 */
class AuditLog extends Model
{
    use HasUuids;

    protected $primaryKey = 'sequence';

    public $timestamps = false;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'context' => 'array',
        ];
    }
}
