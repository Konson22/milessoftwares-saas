<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One channel (email or push) of one notification (ADR-0027 §2.4): unique
 * per notification and channel, tried with backoff, its outcome kept.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $notification_id
 * @property string $channel
 * @property string $status
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $sent_at
 * @property string|null $last_error
 * @property list<array{device: string, ticket?: string|null, error?: string|null}>|null $tickets
 * @property CarbonImmutable|null $receipts_checked_at
 * @property-read Notification $notification
 */
class NotificationDelivery extends Model
{
    use TenantModel;

    public const CHANNELS = ['email', 'push'];

    protected $table = 'log_notification_deliveries';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'receipts_checked_at' => 'immutable_datetime',
            'tickets' => 'array',
        ];
    }

    /** @return BelongsTo<Notification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
