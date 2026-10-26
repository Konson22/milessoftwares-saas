<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A term (primary schools) or semester of an academic year: what registers,
 * marks and fees belong to.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $academic_year_id
 * @property string $name
 * @property int $sequence
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property-read AcademicYear $academicYear
 */
class Term extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return HasMany<Assessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class)->orderBy('position');
    }

    /**
     * @return HasMany<FeeItem, $this>
     */
    public function feeItems(): HasMany
    {
        return $this->hasMany(FeeItem::class)->orderBy('name');
    }

    /** "Term 2, 2026". */
    public function title(): string
    {
        return "{$this->name}, {$this->academicYear->name}";
    }
}
