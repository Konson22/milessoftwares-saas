<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Example tenant-owned record. BelongsToTenant scopes every query to the
 * current organization and fails closed without one.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $title
 * @property string $created_by
 */
class Record extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['title'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
