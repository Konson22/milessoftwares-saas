<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One side of a journal: a debit or a credit to one account.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $journal_id
 * @property string $account_id
 * @property int $position
 * @property int $debit_minor
 * @property int $credit_minor
 * @property string|null $description
 * @property string|null $tax_kind output (on sales) or input (on purchases), on tax account lines
 * @property string|null $tax_code_id
 * @property int|null $tax_base_minor the net amount the tax was charged on
 * @property string|null $cost_centre_id
 * @property string|null $project_id
 * @property string|null $currency set on lines in another currency than the books'
 * @property int|null $currency_minor the amount in that currency, positive on the debit side
 * @property-read Journal $journal
 * @property-read Account $account
 * @property-read TaxCode|null $taxCode
 * @property-read DimensionValue|null $costCentre
 * @property-read DimensionValue|null $project
 */
class JournalLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OUTPUT = 'output';

    public const INPUT = 'input';

    public $timestamps = false;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
            'tax_base_minor' => 'integer',
            'currency_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    /**
     * @return BelongsTo<DimensionValue, $this>
     */
    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(DimensionValue::class, 'cost_centre_id');
    }

    /**
     * @return BelongsTo<DimensionValue, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(DimensionValue::class, 'project_id');
    }
}
