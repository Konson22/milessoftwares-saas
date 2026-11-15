<?php

namespace App\Collect\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Mel\Models\Programme;
use App\Mel\Models\ProgrammeUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A survey form (ADR-0012 §5): a working draft, edited freely, and the
 * versions published from it. Optionally for a programme and one of its
 * units; a form may also stand alone. Archived, never deleted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $programme_id
 * @property string|null $programme_unit_id
 * @property string $name
 * @property string|null $description
 * @property string $purpose
 * @property string $status
 * @property array<string, mixed> $draft_definition
 * @property CarbonImmutable $draft_updated_at
 * @property string|null $draft_updated_by
 * @property string|null $current_version_id
 * @property bool $contains_personal_data
 * @property CarbonImmutable|null $accept_until
 * @property CarbonImmutable|null $archived_at
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Programme|null $programme
 * @property-read ProgrammeUnit|null $unit
 * @property-read FormVersion|null $currentVersion
 * @property-read Collection<int, FormVersion> $versions
 * @property-read Collection<int, Assignment> $assignments
 * @property-read User|null $draftEditor
 */
class Form extends Model
{
    use TenantModel;

    protected $table = 'collect_forms';

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    public const PURPOSES = ['survey', 'assessment', 'monitoring_visit', 'inspection', 'research', 'other'];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'draft_definition' => 'array',
            'draft_updated_at' => 'immutable_datetime',
            'contains_personal_data' => 'boolean',
            'accept_until' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->status === self::ARCHIVED;
    }

    /**
     * Whether the draft differs from the current published version.
     */
    public function hasUnpublishedChanges(): bool
    {
        $current = $this->currentVersion;

        return $current === null || $current->sha256 !== FormVersion::hash($this->draft_definition);
    }

    /**
     * Whether a submission may still arrive for it.
     */
    public function acceptsSubmissions(): bool
    {
        return ! $this->isArchived() || ($this->accept_until !== null && $this->accept_until->isFuture());
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return BelongsTo<ProgrammeUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProgrammeUnit::class, 'programme_unit_id');
    }

    /**
     * @return BelongsTo<FormVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<FormVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class)->orderByDesc('number');
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function draftEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_updated_by');
    }
}
