<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A fee charged in a term (tuition, lunch, exam fee...), to one class or,
 * without a class, to every class.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $term_id
 * @property string|null $school_class_id
 * @property string $name
 * @property int $amount_minor
 * @property-read SchoolClass|null $schoolClass
 */
class FeeItem extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['term_id', 'school_class_id', 'name', 'amount_minor'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }
}
