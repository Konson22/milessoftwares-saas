<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment the funder is due to make (scheduled), or has made (received).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $grant_id
 * @property CarbonImmutable $due_on
 * @property int $amount_minor
 * @property CarbonImmutable|null $received_on
 * @property string|null $note
 * @property string $status planned, requested, approved, rejected, sent, received, disputed (App\Actions\GrantAgreements)
 * @property CarbonImmutable|null $requested_at
 * @property string|null $requested_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decided_by
 * @property string|null $decision_note
 * @property CarbonImmutable|null $sent_on the donor's own record, not a Finance confirmation
 * @property string|null $sent_reference
 * @property string|null $sent_by
 * @property string|null $received_by
 * @property string|null $dispute_note
 */
class GrantDisbursement extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id', 'status', 'requested_at', 'requested_by', 'decided_at', 'decided_by', 'decision_note', 'sent_on', 'sent_reference', 'sent_by', 'received_by', 'dispute_note'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'received_on' => 'date', 'amount_minor' => 'integer', 'requested_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'sent_on' => 'date'];
    }
}
