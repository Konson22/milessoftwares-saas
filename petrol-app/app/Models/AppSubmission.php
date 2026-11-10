<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Something a device did offline, applied here once. The id is made on the device.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $user_id
 * @property string $kind
 * @property array<string, mixed> $result
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable $created_at
 */
class AppSubmission extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $keyType = 'string';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'queued_at' => 'datetime',
        ];
    }
}
