<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A review decision on one submitted revision. Append-only.
 *
 * @property string $id
 * @property string $log_entry_id
 * @property int $revision
 * @property string $decision
 * @property string|null $comment
 * @property string $reviewer_id
 * @property string $reviewer_name
 * @property CarbonImmutable $decided_at
 */
class EntryReview extends Model
{
    use TenantModel;

    public $timestamps = false;

    protected $table = 'log_entry_reviews';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'decided_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'log_entry_id');
    }
}
