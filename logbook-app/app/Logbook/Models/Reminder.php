<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A reminder due at a moment, for a task, a schedule occurrence or an
 * exception. The recipient is worked out when it is sent; sent once.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $kind
 * @property int $offset_minutes
 * @property CarbonImmutable $remind_at
 * @property CarbonImmutable|null $sent_at
 * @property string|null $outcome
 * @property CarbonImmutable|null $cancelled_at
 */
class Reminder extends Model
{
    use TenantModel;

    protected $table = 'log_reminders';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'offset_minutes' => 'integer',
            'remind_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
