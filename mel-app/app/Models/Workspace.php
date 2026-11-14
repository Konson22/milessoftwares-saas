<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * M&E's settings for one organization (one row per tenant).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $display_name
 * @property string $currency
 * @property string $timezone
 * @property bool $verifier_must_differ
 * @property bool $publisher_must_differ
 * @property int $on_track_percent
 * @property int $attention_percent
 */
class Workspace extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'display_name', 'currency', 'timezone',
        'verifier_must_differ', 'publisher_must_differ', 'on_track_percent', 'attention_percent',
    ];

    protected $attributes = [
        'verifier_must_differ' => true,
        'publisher_must_differ' => false,
        'on_track_percent' => 90,
        'attention_percent' => 70,
    ];

    protected function casts(): array
    {
        return [
            'verifier_must_differ' => 'boolean',
            'publisher_must_differ' => 'boolean',
            'on_track_percent' => 'integer',
            'attention_percent' => 'integer',
        ];
    }

    /**
     * The current tenant's row (null until the platform has provisioned it).
     */
    public static function current(): ?self
    {
        return static::query()->first();
    }

    /**
     * The current tenant's row, or the defaults when not provisioned yet.
     */
    public static function settings(): self
    {
        return static::current() ?? new self;
    }
}
