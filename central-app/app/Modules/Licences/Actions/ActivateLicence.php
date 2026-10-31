<?php

namespace App\Modules\Licences\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Licences\Models\LicenceActivation;
use App\Modules\Licences\Models\LicenceKey;
use App\Modules\Licences\Support\LicenceCodes;
use App\Modules\Licences\Support\LicenceSigner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * A licence key on a PC: ties the key to the device and the pharmacy (once;
 * the same PC may ask again, for a lost code) and answers the signed
 * activation code the app keeps.
 */
final class ActivateLicence
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{ip?: string|null, app_version?: string|null}  $meta
     * @return array{code: string, activation: LicenceActivation}
     *
     * @throws LicenceDenied
     */
    public function handle(
        string $key,
        string $device,
        string $pharmacy,
        ?string $pharmacyName,
        string $channel,
        array $meta = [],
        ?Authenticatable $actor = null,
    ): array {
        $signer = LicenceSigner::fromConfig();
        if (! $signer->configured()) {
            throw LicenceDenied::notConfigured();
        }

        $hash = LicenceCodes::hashKey($key) ?? throw LicenceDenied::unknownKey();
        $deviceCode = LicenceCodes::device($device) ?? throw LicenceDenied::badDevice();
        $pharmacyId = LicenceCodes::pharmacy($pharmacy) ?? throw LicenceDenied::badPharmacy();
        $name = trim((string) $pharmacyName) !== '' ? mb_substr(trim((string) $pharmacyName), 0, 120) : null;

        [$licenceKey, $activation, $new] = DB::transaction(function () use ($hash, $deviceCode, $pharmacyId, $name, $channel, $meta) {
            $licenceKey = LicenceKey::query()->where('key_hash', $hash)->lockForUpdate()->first() ?? throw LicenceDenied::unknownKey();
            if ($licenceKey->revoked_at !== null) {
                throw LicenceDenied::revoked();
            }

            $current = $licenceKey->activations()->whereNull('released_at')->latest('activated_at')->first();
            if ($current !== null) {
                if ($current->device_code !== $deviceCode || $current->pharmacy_id !== $pharmacyId) {
                    throw LicenceDenied::inUse();
                }

                // The same PC again (a reinstall, a lost code): the same licence, signed afresh.
                if ($name !== null && $name !== $current->pharmacy_name) {
                    $current->forceFill(['pharmacy_name' => $name])->save();
                }

                return [$licenceKey, $current, false];
            }

            $now = now();
            $activation = $licenceKey->activations()->make()->forceFill([
                'device_code' => $deviceCode,
                'pharmacy_id' => $pharmacyId,
                'pharmacy_name' => $name ?? $licenceKey->customer_name,
                'activated_at' => $now,
                'expires_at' => $licenceKey->duration_months === null ? null : $now->copy()->addMonthsNoOverflow($licenceKey->duration_months),
                'channel' => $channel,
                'ip_address' => $meta['ip'] ?? null,
                'app_version' => isset($meta['app_version']) ? mb_substr((string) $meta['app_version'], 0, 32) : null,
            ]);
            $activation->save();

            return [$licenceKey, $activation, true];
        });

        if ($new) {
            $this->audit->record('licence.activated', $activation, [
                'key_hint' => $licenceKey->key_hint,
                'device' => $deviceCode,
                'pharmacy' => $pharmacyId,
                'channel' => $channel,
            ], actor: $actor, actorType: $actor === null ? 'user' : 'staff');
        }

        return ['code' => $this->code($licenceKey, $activation, $signer), 'activation' => $activation];
    }

    private function code(LicenceKey $key, LicenceActivation $activation, LicenceSigner $signer): string
    {
        return $signer->sign([
            'id' => $activation->id,
            'type' => $key->type,
            'device' => $activation->device_code,
            'branch' => $activation->pharmacy_id,
            'name' => (string) ($activation->pharmacy_name ?? ''),
            'issued' => $activation->activated_at->toIso8601ZuluString('millisecond'),
            'expires' => $activation->expires_at?->toIso8601ZuluString('millisecond'),
            'max_devices' => 1,
            'max_branches' => 1,
        ]);
    }
}
