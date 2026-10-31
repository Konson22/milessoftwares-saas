<?php

namespace App\Modules\Licences\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A licence key on one PC and one pharmacy. Its id is the licence id in the
 * signed code. Released when the key moves to another PC.
 *
 * @property string $id
 * @property string $licence_key_id
 * @property string $device_code
 * @property string $pharmacy_id
 * @property string|null $pharmacy_name
 * @property Carbon $activated_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $released_at
 * @property string $channel app|web|staff
 * @property string|null $ip_address
 * @property string|null $app_version
 * @property-read LicenceKey $licenceKey
 */
class LicenceActivation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LicenceKey, $this> */
    public function licenceKey(): BelongsTo
    {
        return $this->belongsTo(LicenceKey::class);
    }
}
