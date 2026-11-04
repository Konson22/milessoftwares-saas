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
 * @property string $display_name
 * @property string $currency
 * @property string $timezone
 * @property bool $journal_approval manual journals need a second person's approval
 * @property int|null $journal_approval_threshold_minor only from this total up (null: all)
 * @property bool $payment_approval direct payments are held as payment requests
 * @property int|null $payment_approval_threshold_minor below this, payments need no approval (null: all do)
 * @property bool $payment_review requests are reviewed before approval
 * @property bool $payment_separation one person cannot act twice on the same payment
 */
class Workspace extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['display_name', 'currency', 'timezone'];

    protected function casts(): array
    {
        return [
            'journal_approval' => 'boolean',
            'journal_approval_threshold_minor' => 'integer',
            'payment_approval' => 'boolean',
            'payment_approval_threshold_minor' => 'integer',
            'payment_review' => 'boolean',
            'payment_separation' => 'boolean',
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
