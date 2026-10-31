<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Someone who works on the workspace's projects: a member who has opened
 * the app (user_id set, App\Http\Middleware\RememberPerson) or someone
 * added by hand. An hour of their time costs hourly_cost_minor.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $user_id
 * @property string $name
 * @property string|null $email
 * @property string|null $title
 * @property int $hourly_cost_minor
 * @property bool $active
 * @property-read User|null $user
 */
class Person extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'people';

    protected $fillable = ['name', 'email', 'title', 'hourly_cost_minor', 'active'];

    protected function casts(): array
    {
        return ['hourly_cost_minor' => 'integer', 'active' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The signed-in member's own person (null before RememberPerson made it).
     */
    public static function forUser(?User $user): ?self
    {
        return $user === null ? null : static::query()->where('user_id', $user->id)->first();
    }

    /**
     * Active people, to choose from.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_values(static::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (self $person) => ['value' => $person->id, 'label' => $person->name])
            ->all());
    }
}
