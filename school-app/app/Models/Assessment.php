<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Something a term is assessed on (mid-term exam, coursework, final exam),
 * marked out of 100 and counting for its weight in the term's result. A
 * term's weights add up to at most 100.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $term_id
 * @property string $name
 * @property int $weight
 * @property int $position
 * @property-read Term $term
 */
class Assessment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['term_id', 'name', 'weight', 'position'];

    protected function casts(): array
    {
        return ['weight' => 'integer', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return HasMany<Mark, $this>
     */
    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }
}
