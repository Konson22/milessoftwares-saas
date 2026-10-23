<?php

namespace App\Modules\Leave\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A kind of leave the organization gives (annual, sick, unpaid...). An
 * archived type takes no new requests; its history stays.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property int $days_per_year
 * @property bool $paid
 * @property bool $allow_negative
 * @property int $max_carry_over
 * @property CarbonImmutable|null $archived_at
 */
class LeaveType extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'days_per_year' => 'integer',
            'paid' => 'boolean',
            'allow_negative' => 'boolean',
            'max_carry_over' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Kinds of leave that take new requests.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_values(self::query()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(fn (self $type) => ['value' => $type->id, 'label' => $type->name])
            ->all());
    }
}
