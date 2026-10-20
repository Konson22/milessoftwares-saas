<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * "Description contains … → post to …": how a statement line that nothing
 * in the ledger matches is entered. Rules are tried in position order.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $bank_account_id
 * @property string $contains
 * @property string $direction
 * @property string $account_id
 * @property string|null $memo
 * @property int $position
 * @property-read Account|null $bankAccount
 * @property-read Account $account
 */
class BankRule extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const IN = 'in';

    public const OUT = 'out';

    public const ANY = 'any';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * Whether a statement line falls under this rule.
     */
    public function applies(BankStatementLine $line): bool
    {
        if ($this->bank_account_id !== null && $this->bank_account_id !== $line->account_id) {
            return false;
        }
        if (($this->direction === self::IN && $line->amount_minor < 0) || ($this->direction === self::OUT && $line->amount_minor > 0)) {
            return false;
        }

        return mb_stripos("{$line->description} {$line->reference}", $this->contains) !== false;
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
