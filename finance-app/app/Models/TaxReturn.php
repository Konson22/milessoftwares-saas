<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A filed tax return: its period's totals as filed, and the payment to (or
 * refund from) the tax authority. Filed by App\Finance\Tax.
 *
 * @property string $id
 * @property string $organization_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string|null $reference
 * @property int $output_minor
 * @property int $input_minor
 * @property int $adjustments_minor
 * @property int $net_minor owed to the tax authority; negative when owed back
 * @property CarbonImmutable $filed_at
 * @property string $filed_by
 * @property string|null $settlement_journal_id
 * @property-read User $filer
 * @property-read Journal|null $settlementJournal
 */
class TaxReturn extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'output_minor' => 'integer',
            'input_minor' => 'integer',
            'adjustments_minor' => 'integer',
            'net_minor' => 'integer',
            'filed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function settlementJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'settlement_journal_id');
    }
}
