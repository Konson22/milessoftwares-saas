<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * This product's settings for one organization (one row per tenant).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $slug
 * @property string $display_name
 * @property string $currency
 * @property string $timezone
 * @property bool $journal_approval manual journals need a second person's approval
 * @property int|null $journal_approval_threshold_minor only from this total up (null: all)
 */
class Workspace extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['slug', 'display_name', 'currency', 'timezone'];

    protected function casts(): array
    {
        return [
            'journal_approval' => 'boolean',
            'journal_approval_threshold_minor' => 'integer',
        ];
    }

    /**
     * The current tenant's row (null until the platform has provisioned it).
     */
    public static function current(): ?self
    {
        return static::query()->first();
    }
}
