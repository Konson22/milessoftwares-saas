<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One move of a Finance request, as it happened. Append-only: a database
 * trigger refuses changes on PostgreSQL, and the model refuses them
 * everywhere.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $finance_request_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string $cause the event type, or what Projects did
 * @property string $source projects or finance
 * @property string|null $event_id
 * @property int|null $finance_sequence
 * @property array<string, mixed> $facts
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 */
class FinanceRequestTransition extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['facts' => 'array', 'finance_sequence' => 'integer', 'occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Finance request history is append-only.'));
        static::deleting(fn () => throw new LogicException('Finance request history is append-only.'));
    }
}
