<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Cash put into (in) or taken out of (out) an open till, with the reason,
 * and where it came from or went: the safe, the bank, or an expense.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $till_session_id
 * @property string $direction
 * @property string $counterpart
 * @property int $amount_minor
 * @property string $reason
 * @property string $recorded_by
 * @property string|null $submission_id
 * @property CarbonImmutable $created_at
 * @property-read User $recorder
 */
class TillCashMovement extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const IN = 'in';

    public const OUT = 'out';

    /** Within the business's cash: nothing for Finance. */
    public const SAFE = 'safe';

    public const BANK = 'bank';

    /** Paid out for something (out only). */
    public const EXPENSE = 'expense';

    public const COUNTERPARTS = [self::SAFE, self::BANK, self::EXPENSE];

    public function label(): string
    {
        return match ([$this->direction, $this->counterpart]) {
            [self::IN, self::BANK] => 'From the bank',
            [self::OUT, self::BANK] => 'Banked',
            [self::OUT, self::EXPENSE] => 'Paid out',
            [self::IN, self::SAFE] => 'From the safe',
            default => 'To the safe',
        };
    }

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TillSession, $this>
     */
    public function till(): BelongsTo
    {
        return $this->belongsTo(TillSession::class, 'till_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
