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
 */
class GrantDisbursement extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'received_on' => 'date', 'amount_minor' => 'integer'];
    }
}
