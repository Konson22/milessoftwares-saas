<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A station safe. Cash in it is custody, not a second copy of sales.
 *
 * @property string $id
 * @property string $branch_id
 * @property string $name
 * @property string $code
 * @property string|null $custodian_user_id
 * @property string $status
 */
class Safe extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $guarded = ['organization_id'];
}
