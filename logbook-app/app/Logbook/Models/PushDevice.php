<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A phone that receives push notifications for one member (ADR-0027 §2.3).
 * The token is the install's; whoever registers it last owns it, so a
 * shared phone never shows the previous person's notices.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $token
 * @property string $platform
 * @property string $install_id
 * @property string|null $app_version
 * @property CarbonImmutable $last_seen_at
 * @property int $failures
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_reason
 */
class PushDevice extends Model
{
    use TenantModel;

    protected $table = 'log_push_devices';

    protected $guarded = ['id', 'organization_id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'failures' => 'integer',
        ];
    }
}
