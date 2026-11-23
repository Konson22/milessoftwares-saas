<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A log sheet: a versioned template that entries are written against.
 * draft (never published), then active, then archived.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $logbook_id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property string|null $instructions
 * @property string $status
 * @property bool $review_required
 * @property string|null $published_version_id
 * @property string $created_by
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Logbook $logbook
 * @property-read LogSheetVersion|null $publishedVersion
 * @property-read LogSheetVersion|null $draftVersion
 */
class LogSheet extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'draft', 'review_required' => false];

    protected function casts(): array
    {
        return ['review_required' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }

    /** Accepts entries: published, not archived, in an active logbook. */
    public function open(): bool
    {
        return $this->status === 'active' && $this->published_version_id !== null && $this->logbook->isActive();
    }

    /** @return BelongsTo<Logbook, $this> */
    public function logbook(): BelongsTo
    {
        return $this->belongsTo(Logbook::class);
    }

    /** @return HasMany<LogSheetVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(LogSheetVersion::class)->orderByDesc('version');
    }

    /** @return BelongsTo<LogSheetVersion, $this> */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(LogSheetVersion::class, 'published_version_id');
    }

    /** @return HasOne<LogSheetVersion, $this> */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(LogSheetVersion::class)->where('status', 'draft');
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /** ADR-0031: the assets it records. None: any asset its resource fields allow. @return BelongsToMany<LogResource, $this> */
    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(LogResource::class, 'log_sheet_resources', 'log_sheet_id', 'log_resource_id')
            ->withPivot(['id', 'organization_id', 'created_by'])->withTimestamps();
    }
}
