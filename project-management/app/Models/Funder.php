<?php

namespace App\Models;

use App\Enums\FunderKind;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone who funds the organization's work. Each organization keeps its
 * own funder directory: being a donor is a relationship, not a type.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property FunderKind $kind
 * @property string|null $country_code
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $website
 * @property string|null $notes
 * @property bool $active
 */
class Funder extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['kind' => FunderKind::class, 'active' => 'boolean'];
    }

    /**
     * @return HasMany<Grant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }

    /**
     * For selects: active funders, by name.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_values(static::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (self $funder) => ['value' => $funder->id, 'label' => $funder->name])
            ->all());
    }
}
