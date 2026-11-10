<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One station's rate from a moment until the next rate. Closed rows are
 * history. A sale copies the numbers it used, so editing history is not how a rate changes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $base_currency
 * @property string $quote_currency
 * @property int $rate_minor
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $created_by
 */
class ExchangeRate extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'rate_minor' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public static function open(string $branchId, string $from, string $to): ?self
    {
        if ($from === $to) {
            return null;
        }

        return static::query()
            ->where('branch_id', $branchId)
            ->whereNull('effective_to')
            ->where(function ($query) use ($from, $to) {
                $query->where(fn ($pair) => $pair->where('base_currency', $from)->where('quote_currency', $to))
                    ->orWhere(fn ($pair) => $pair->where('base_currency', $to)->where('quote_currency', $from));
            })
            ->first();
    }
}
