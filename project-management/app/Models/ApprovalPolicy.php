<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A band of an organization's approval policy: from min_amount_minor up,
 * in this currency, spending needs approvals_required approvers. The band
 * with the highest minimum at or below an amount applies; without one,
 * a single approver. Set in Administration → Settings.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $subject
 * @property string $currency
 * @property int $min_amount_minor
 * @property int $approvals_required
 * @property string $created_by
 * @property CarbonImmutable $created_at
 */
class ApprovalPolicy extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const EXPENSE = 'expense';

    /** At most this many approvers for one request. */
    public const MAX_APPROVERS = 3;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['min_amount_minor' => 'integer', 'approvals_required' => 'integer'];
    }

    /**
     * How many approvers spending of this amount needs now.
     */
    public static function approversFor(string $currency, int $amountMinor): int
    {
        $band = static::query()
            ->where('subject', self::EXPENSE)->where('currency', $currency)
            ->where('min_amount_minor', '<=', $amountMinor)
            ->orderByDesc('min_amount_minor')->first();

        return $band->approvals_required ?? 1;
    }
}
