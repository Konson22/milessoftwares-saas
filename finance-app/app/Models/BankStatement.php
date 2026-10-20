<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A bank statement for one bank or cash account. Imported by
 * App\Finance\Reconciliation; reconciled once every line is matched.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $account_id
 * @property string|null $reference
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int $opening_balance_minor
 * @property int $closing_balance_minor
 * @property CarbonImmutable|null $reconciled_at
 * @property string|null $reconciled_by
 * @property string $imported_by
 * @property CarbonImmutable $created_at
 * @property-read Account $account
 * @property-read Collection<int, BankStatementLine> $lines
 * @property-read User|null $reconciler
 * @property-read User $importer
 */
class BankStatement extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'opening_balance_minor' => 'integer',
            'closing_balance_minor' => 'integer',
            'reconciled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function reconciled(): bool
    {
        return $this->reconciled_at !== null;
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<BankStatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
