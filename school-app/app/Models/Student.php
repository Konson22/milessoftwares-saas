<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A pupil or student, numbered ADM-00001 (primary) or REG-00001: ACTIVE in a
 * class, SUSPENDED, WITHDRAWN, or GRADUATED after the final year.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $first_name
 * @property string $last_name
 * @property string $gender
 * @property CarbonImmutable|null $date_of_birth
 * @property string|null $school_class_id
 * @property string $status
 * @property CarbonImmutable $admitted_on
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $guardian_name
 * @property string|null $guardian_phone
 * @property string|null $guardian_relationship
 * @property-read SchoolClass|null $schoolClass
 */
class Student extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const WITHDRAWN = 'withdrawn';

    public const GRADUATED = 'graduated';

    public const STATUSES = [self::ACTIVE, self::SUSPENDED, self::WITHDRAWN, self::GRADUATED];

    public const GENDERS = ['female', 'male'];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'admitted_on' => 'date'];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function name(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Number, name or guardian's phone containing the text.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $like = '%'.mb_strtolower($text).'%';
        $query->where(fn (Builder $query) => $query
            ->whereRaw('lower(number) like ?', [$like])
            ->orWhereRaw('lower(first_name || \' \' || last_name) like ?', [$like])
            ->orWhereRaw('lower(coalesce(guardian_phone, \'\')) like ?', [$like])
            ->orWhereRaw('lower(coalesce(phone, \'\')) like ?', [$like]));
    }
}
