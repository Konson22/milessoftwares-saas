<?php

namespace App\Mel\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One entry in the M&E audit trail. Append-only (App\Mel\AuditTrail).
 *
 * @property string $id
 * @property string $organization_id
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

    protected $table = 'mel_audit_events';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
