<?php

namespace App\Models;

use App\Enums\SchoolCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * This product's settings for one organization (one row per tenant): what
 * kind of school it is and the term or semester it is in.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $slug
 * @property string $display_name
 * @property string $currency
 * @property string $timezone
 * @property SchoolCategory|null $category
 * @property string|null $current_term_id
 * @property-read Term|null $currentTerm
 */
class Workspace extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['slug', 'display_name', 'currency', 'timezone'];

    protected function casts(): array
    {
        return ['category' => SchoolCategory::class];
    }

    /**
     * The current tenant's row (null until the platform has provisioned it).
     */
    public static function current(): ?self
    {
        return static::query()->first();
    }

    /**
     * The school's category, or null while it is not set up.
     */
    public static function category(): ?SchoolCategory
    {
        return static::current()?->category;
    }

    /**
     * The term or semester the school is in.
     */
    public static function term(): ?Term
    {
        return static::current()?->currentTerm;
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function currentTerm(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'current_term_id');
    }
}
