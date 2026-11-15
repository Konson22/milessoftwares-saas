<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One switch of an organization's Finance mode: who, why, and what the
 * checks said at the time.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $from_mode
 * @property string $to_mode
 * @property string $reason
 * @property array<string, mixed> $checks
 * @property string $changed_by
 * @property CarbonImmutable $created_at
 */
class FinanceModeChange extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
