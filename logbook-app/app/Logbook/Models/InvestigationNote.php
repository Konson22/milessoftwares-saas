<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An observation, hypothesis, finding, conclusion or follow-up written by
 * a person. Changing it adds a new row that supersedes this one; the old
 * wording is kept.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $investigation_id
 * @property string $kind
 * @property string $body
 * @property int $version
 * @property string|null $supersedes_id
 * @property CarbonImmutable|null $superseded_at
 * @property string $author_id
 * @property CarbonImmutable $created_at
 * @property-read User $author
 */
class InvestigationNote extends Model
{
    use TenantModel;

    public const KINDS = ['observation', 'hypothesis', 'finding', 'conclusion', 'follow_up'];

    public $timestamps = false;

    protected $table = 'log_investigation_notes';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
