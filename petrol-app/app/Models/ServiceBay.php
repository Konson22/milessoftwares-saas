<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $branch_id
 * @property string $name
 * @property string $code
 * @property string $status
 * @property string|null $supervisor
 */
class ServiceBay extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $guarded = ['organization_id'];
}
