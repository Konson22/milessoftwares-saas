<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * A member's email choices, by notification category (missing: on).
 *
 * @property string $id
 * @property string $user_id
 * @property array<string, bool> $email
 * @property array<string, bool>|null $push
 */
class NotificationSetting extends Model
{
    use TenantModel;

    protected $table = 'log_notification_settings';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['email' => 'array', 'push' => 'array'];
    }
}
