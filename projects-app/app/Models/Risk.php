<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Something that could go wrong on a project, scored probability × impact
 * (each 1 to 5): 15 and above is high, 8 and above medium.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $title
 * @property string|null $description
 * @property int $probability
 * @property int $impact
 * @property string $status
 * @property string|null $owner_id
 * @property string|null $mitigation
 * @property CarbonImmutable|null $review_on
 * @property-read Person|null $owner
 */
class Risk extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPEN = 'open';

    public const MITIGATING = 'mitigating';

    public const CLOSED = 'closed';

    public const STATUSES = [self::OPEN, self::MITIGATING, self::CLOSED];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['probability' => 'integer', 'impact' => 'integer', 'review_on' => 'date'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'owner_id');
    }

    public function score(): int
    {
        return $this->probability * $this->impact;
    }

    public function level(): string
    {
        return match (true) {
            $this->score() >= 15 => 'high',
            $this->score() >= 8 => 'medium',
            default => 'low',
        };
    }
}
