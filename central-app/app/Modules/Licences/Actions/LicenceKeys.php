<?php

namespace App\Modules\Licences\Actions;

use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Audit\AuditLogger;
use App\Modules\Licences\Models\LicenceKey;
use App\Modules\Licences\Support\LicenceCodes;
use Illuminate\Support\Facades\DB;

/**
 * Licence keys from staff: made (sold by hand, printed on a card), cancelled,
 * or freed from their PC to move to another.
 */
final class LicenceKeys
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{customer_name?: string|null, customer_email?: string|null, customer_phone?: string|null, duration_months?: int|null, note?: string|null}  $details
     * @return list<array{key: string, model: LicenceKey}> Each key in full: shown once, then only its hash is kept.
     */
    public function issue(int $count, array $details, ?PlatformStaff $staff): array
    {
        return DB::transaction(function () use ($count, $details, $staff) {
            $made = [];
            for ($i = 0; $i < $count; $i++) {
                $key = LicenceCodes::newKey();
                $model = new LicenceKey;
                $model->forceFill([
                    'key_hash' => LicenceCodes::hashKey($key),
                    'key_hint' => substr(LicenceCodes::keyBody($key) ?? '', -4),
                    'product' => 'pharmacy',
                    'type' => 'single',
                    'duration_months' => $details['duration_months'] ?? null,
                    'customer_name' => $details['customer_name'] ?? null,
                    'customer_email' => $details['customer_email'] ?? null,
                    'customer_phone' => $details['customer_phone'] ?? null,
                    'note' => $details['note'] ?? null,
                    'issued_by_staff_id' => $staff?->id,
                ])->save();

                $this->audit->record('licence.key_issued', $model, ['key_hint' => $model->key_hint], actor: $staff?->user, actorType: 'staff');
                $made[] = ['key' => $key, 'model' => $model];
            }

            return $made;
        });
    }

    /** Cancelled: it activates nothing more. A PC it is on keeps its code until that ends (the app works offline). */
    public function revoke(LicenceKey $key, string $reason, ?PlatformStaff $staff): void
    {
        DB::transaction(function () use ($key, $reason) {
            $key->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason])->save();
            $key->activations()->whereNull('released_at')->update(['released_at' => now()]);
        });

        $this->audit->record('licence.key_revoked', $key, ['key_hint' => $key->key_hint, 'reason' => $reason], actor: $staff?->user, actorType: 'staff');
    }

    /** Freed from its PC (a new or broken one): it activates on the next PC that asks. */
    public function release(LicenceKey $key, string $reason, ?PlatformStaff $staff): void
    {
        $released = $key->activations()->whereNull('released_at')->update(['released_at' => now()]);

        if ($released > 0) {
            $this->audit->record('licence.released', $key, ['key_hint' => $key->key_hint, 'reason' => $reason], actor: $staff?->user, actorType: 'staff');
        }
    }
}
