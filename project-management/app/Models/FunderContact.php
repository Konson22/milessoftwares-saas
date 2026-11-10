<?php

namespace App\Models;

use App\Access\CurrentAccess;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who acts for a funder: a member of the organization holding only
 * the funder contact's permission (role "Funder contact" on the platform),
 * who sees only what App\Support\DonorProjection returns. Written by
 * App\Actions\DonorPortal.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $funder_id
 * @property string $user_id
 * @property string|null $created_by
 * @property-read Funder $funder
 * @property-read User $user
 */
class FunderContact extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    /**
     * @return BelongsTo<Funder, $this>
     */
    public function funder(): BelongsTo
    {
        return $this->belongsTo(Funder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What a funder's contact holds on the platform (platform/catalog.json). */
    public const PERMISSION = 'projects.donor.view';

    public static function forUser(?User $user): ?self
    {
        return $user === null ? null : self::query()->where('user_id', $user->id)->first();
    }

    /**
     * A contact holding nothing else here is kept to the portal, whatever a
     * page elsewhere would have shown a member without permissions.
     */
    public static function isConfined(?User $user): bool
    {
        $access = app(CurrentAccess::class)->get();

        return $access !== null && ! $access->isOwner && array_diff($access->all(), [self::PERMISSION]) === [] && self::forUser($user) !== null;
    }
}
