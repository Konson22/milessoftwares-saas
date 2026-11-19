<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $action
 * @property string $subject_type
 * @property string|null $subject_id
 * @property string $subject_label
 * @property string|null $logbook_id
 * @property string|null $user_id
 * @property string|null $user_name
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable $created_at
 */
class AuditEvent extends Model
{
    use TenantModel;

    public const UPDATED_AT = null;

    protected $table = 'log_audit_events';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
