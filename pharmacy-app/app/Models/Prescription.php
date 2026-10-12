<?php

namespace App\Models;

use App\Enums\PrescriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A prescription brought to a branch: recorded, verified by a pharmacist,
 * then dispensed as a sale.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $number
 * @property string $patient_name
 * @property int|null $patient_age
 * @property string $prescriber_name
 * @property string|null $prescriber_registration
 * @property CarbonImmutable $issued_on
 * @property PrescriptionStatus $status
 * @property string|null $notes
 * @property string $recorded_by
 * @property string|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property string|null $rejection_reason
 * @property string|null $dispensed_sale_id
 * @property CarbonImmutable $created_at
 * @property-read User $recorder
 * @property-read User|null $verifier
 * @property-read Sale|null $sale
 * @property-read Collection<int, PrescriptionItem> $items
 */
class Prescription extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** A prescription can be dispensed until this many days after it was issued. */
    public const VALID_DAYS = 30;

    protected $guarded = ['organization_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => PrescriptionStatus::class,
            'issued_on' => 'immutable_date',
            'verified_at' => 'immutable_datetime',
            'patient_age' => 'integer',
        ];
    }

    public function expiresOn(): CarbonImmutable
    {
        return $this->issued_on->addDays(self::VALID_DAYS);
    }

    public function isExpired(?CarbonImmutable $today = null): bool
    {
        return ($today ?? CarbonImmutable::today())->greaterThan($this->expiresOn());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'dispensed_sale_id');
    }

    /**
     * @return HasMany<PrescriptionItem, $this>
     */
    public function items(): HasMany
    {
        // Ids are time-ordered (UUID v7): items as they were written.
        return $this->hasMany(PrescriptionItem::class)->orderBy('id');
    }
}
