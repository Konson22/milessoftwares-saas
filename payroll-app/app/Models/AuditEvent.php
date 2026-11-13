<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One entry in Payroll's audit trail. Never changed or deleted.
 *
 * @property string $id
 * @property string $action
 * @property string $subject_type
 * @property string|null $subject_id
 * @property string $subject_label
 * @property string|null $user_id
 * @property string|null $user_name
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable $created_at
 */
class AuditEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'payroll_audit_events';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
