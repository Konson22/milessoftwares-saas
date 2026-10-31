<?php

namespace App\Modules\Licences\Models;

use App\Modules\Administration\Models\PlatformStaff;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A licence key for the offline desktop pharmacy. Written by IssueLicenceKeys
 * and its activation by ActivateLicence only.
 *
 * @property string $id
 * @property string $key_hash
 * @property string $key_hint
 * @property string $product
 * @property string $type single
 * @property int|null $duration_months
 * @property string|null $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property string|null $note
 * @property string|null $issued_by_staff_id
 * @property Carbon|null $revoked_at
 * @property string|null $revoke_reason
 * @property Carbon|null $created_at
 * @property-read LicenceActivation|null $activation
 */
class LicenceKey extends Model
{
    use HasUuids;

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return HasMany<LicenceActivation, $this> */
    public function activations(): HasMany
    {
        return $this->hasMany(LicenceActivation::class);
    }

    /**
     * The PC it is on now, if any.
     *
     * @return HasOne<LicenceActivation, $this>
     */
    public function activation(): HasOne
    {
        // One at most: activating another PC needs this one released first.
        return $this->hasOne(LicenceActivation::class)->whereNull('released_at')->latest('activated_at');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'issued_by_staff_id');
    }

    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->activation === null ? 'available' : 'active';
    }
}
