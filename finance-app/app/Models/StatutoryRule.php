<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Income tax bands (a month) and social security rates from a date: data
 * the organization keeps up to date, never code.
 *
 * @property string $id
 * @property CarbonImmutable $effective_from
 * @property string $name
 * @property list<array{up_to_minor: int|null, rate_bp: int}> $income_tax_bands
 * @property int $social_security_employee_bp
 * @property int $social_security_employer_bp
 * @property int|null $social_security_cap_minor
 * @property bool $social_security_deductible
 * @property string $created_by
 * @property-read User $creator
 */
class StatutoryRule extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'income_tax_bands' => 'array',
            'social_security_employee_bp' => 'integer',
            'social_security_employer_bp' => 'integer',
            'social_security_cap_minor' => 'integer',
            'social_security_deductible' => 'boolean',
        ];
    }

    public static function inForceOn(CarbonImmutable $date): ?self
    {
        return static::query()
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
