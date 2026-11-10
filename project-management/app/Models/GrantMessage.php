<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The conversation between the organization and the funder on a grant. Never
 * edited or deleted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $grant_id
 * @property string $user_id
 * @property bool $from_funder
 * @property string $body
 * @property CarbonImmutable $created_at
 * @property-read User $author
 */
class GrantMessage extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['from_funder' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
