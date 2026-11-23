<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An operational shift (ADR-0030 §4.5): a name and local wall times. An
 * end before the start runs past midnight. Optional people only suggest.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $starts_time
 * @property string $ends_time
 * @property string|null $logbook_id
 * @property bool $active
 * @property string $created_by
 * @property-read Logbook|null $logbook
 * @property-read Collection<int, User> $members
 */
class Shift extends Model
{
    use TenantModel;

    protected $table = 'log_shifts';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsTo<Logbook, $this> */
    public function logbook(): BelongsTo
    {
        return $this->belongsTo(Logbook::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'log_shift_members', 'shift_id', 'user_id')
            ->withPivot(['id', 'organization_id'])->withTimestamps();
    }

    /** "Night · 22:00–06:00". */
    public function label(): string
    {
        return "{$this->name} · {$this->starts_time}–{$this->ends_time}";
    }

    public function overnight(): bool
    {
        return $this->ends_time < $this->starts_time;
    }
}
