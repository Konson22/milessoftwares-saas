<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a member should know (ADR-0026 §2.8). One per recipient and
 * dedupe key. Its text names a reference and a title, never values.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $category
 * @property string $event
 * @property string $title
 * @property string|null $body
 * @property string $subject_type
 * @property string $subject_id
 * @property string $link
 * @property string $dedupe_key
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $emailed_at
 * @property CarbonImmutable $created_at
 */
class Notification extends Model
{
    use TenantModel;

    public const CATEGORIES = ['task', 'entry', 'schedule', 'exception'];

    public const UPDATED_AT = null;

    protected $table = 'log_notifications';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime', 'emailed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
