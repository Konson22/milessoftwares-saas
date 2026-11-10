<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of projects under one aim. It holds no money or tasks of its own.
 * Archived, never deleted (App\Actions\Programmes).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $manager_id
 * @property CarbonImmutable|null $archived_at
 * @property string $created_by
 * @property-read Person|null $manager
 */
class Programme extends Model
{
    use Archivable;
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    /**
     * "WATER · Clean water".
     */
    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'manager_id');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
