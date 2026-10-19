<?php

namespace App\Modules\Employees\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Who looked at or changed a sensitive HR record. Only field names are
 * kept, never values. Written by App\Support\HrAudit; never updated.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $actor_id
 * @property string $action
 * @property string|null $employee_id
 * @property string|null $subject_id
 * @property list<string>|null $fields
 * @property CarbonImmutable $created_at
 * @property-read User $actor
 */
class AuditEntry extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'hr_audit_log';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
