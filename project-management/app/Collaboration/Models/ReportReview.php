<?php

namespace App\Collaboration\Models;

use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a report's life, or a comment: who, for which organization,
 * on which version. Append-only.
 *
 * @property string $id
 * @property string $report_id
 * @property string|null $version_id
 * @property string $action
 * @property string|null $comment
 * @property string $user_id
 * @property string $acting_organization_id
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read Organization $actingOrganization
 * @property-read ReportVersion|null $version
 */
class ReportReview extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'project_report_reviews';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function actingOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'acting_organization_id');
    }

    /**
     * @return BelongsTo<ReportVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ReportVersion::class, 'version_id');
    }
}
