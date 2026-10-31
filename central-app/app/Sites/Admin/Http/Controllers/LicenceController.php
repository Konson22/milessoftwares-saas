<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Licences\Actions\ActivateLicence;
use App\Modules\Licences\Actions\LicenceDenied;
use App\Modules\Licences\Actions\LicenceKeys;
use App\Modules\Licences\Models\LicenceKey;
use App\Modules\Licences\Support\LicenceCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Licence keys for the offline desktop pharmacy: made by hand (a sale on the
 * phone, a printed card), found by their last four characters, the customer
 * or the PC; cancelled; or freed to move to another PC. A customer with no
 * internet reads out their key and codes, and staff activate it here.
 */
class LicenceController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');

        $query = LicenceKey::query()->with('activation')->latest();
        if ($search !== '') {
            $plain = LicenceCodes::normalize($search);
            $query->where(function ($q) use ($search, $plain) {
                $q->where('customer_name', 'ilike', "%{$search}%")
                    ->orWhere('customer_email', 'ilike', "%{$search}%")
                    ->orWhere('customer_phone', 'ilike', "%{$search}%");
                if (strlen($plain) >= 4) {
                    $q->orWhere('key_hint', substr($plain, -4))
                        ->orWhereHas('activations', fn ($a) => $a->where('device_code', LicenceCodes::device($plain) ?? '')
                            ->orWhere('pharmacy_id', LicenceCodes::pharmacy($plain) ?? '')
                            ->orWhere('pharmacy_name', 'ilike', "%{$search}%"));
                }
            });
        }
        match ($status) {
            'available' => $query->whereNull('revoked_at')->whereDoesntHave('activations', fn ($a) => $a->whereNull('released_at')),
            'active' => $query->whereNull('revoked_at')->whereHas('activations', fn ($a) => $a->whereNull('released_at')),
            'revoked' => $query->whereNotNull('revoked_at'),
            default => null,
        };

        return Inertia::render('admin/licences/index', [
            'search' => $search,
            'status' => $status,
            'keys' => $query->limit(100)->get()->map(fn (LicenceKey $key) => [
                'id' => $key->id,
                'hint' => $key->key_hint,
                'status' => $key->status(),
                'durationMonths' => $key->duration_months,
                'customerName' => $key->customer_name,
                'customerEmail' => $key->customer_email,
                'customerPhone' => $key->customer_phone,
                'note' => $key->note,
                'issuedAt' => $key->created_at?->toIso8601String(),
                'revokedAt' => $key->revoked_at?->toIso8601String(),
                'revokeReason' => $key->revoke_reason,
                'activation' => $key->activation === null ? null : [
                    'device' => $key->activation->device_code,
                    'pharmacy' => $key->activation->pharmacy_id,
                    'pharmacyName' => $key->activation->pharmacy_name,
                    'activatedAt' => $key->activation->activated_at->toIso8601String(),
                    'expiresAt' => $key->activation->expires_at?->toIso8601String(),
                    'channel' => $key->activation->channel,
                ],
            ])->all(),
            'issuedKeys' => $request->session()->get('issued_keys'),
            'signedCode' => $request->session()->get('signed_code'),
        ]);
    }

    public function store(Request $request, LicenceKeys $keys): RedirectResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:50'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $made = $keys->issue((int) $data['count'], $data, $this->staff($request));

        return back()->with('issued_keys', [
            'customer' => $data['customer_name'] ?? null,
            'durationMonths' => $data['duration_months'] ?? null,
            'keys' => array_map(fn ($m) => $m['key'], $made),
        ]);
    }

    public function revoke(Request $request, LicenceKey $licenceKey, LicenceKeys $keys): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $keys->revoke($licenceKey, $data['reason'], $this->staff($request));

        return back();
    }

    public function release(Request $request, LicenceKey $licenceKey, LicenceKeys $keys): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $keys->release($licenceKey, $data['reason'], $this->staff($request));

        return back();
    }

    /** For a customer with no internet: their key and codes, read out; the code goes back by phone, WhatsApp or email. */
    public function activate(Request $request, ActivateLicence $activate): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'device' => ['required', 'string', 'max:32'],
            'pharmacy' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $activate->handle($data['key'], $data['device'], $data['pharmacy'], $data['name'] ?? null, 'staff', ['ip' => $request->ip()], $this->staff($request)?->user);
        } catch (LicenceDenied $denied) {
            throw ValidationException::withMessages([$denied->field => $denied->getMessage()]);
        }

        return back()->with('signed_code', [
            'code' => $result['code'],
            'device' => $result['activation']->device_code,
            'pharmacy' => $result['activation']->pharmacy_id,
        ]);
    }

    private function staff(Request $request): ?PlatformStaff
    {
        $staff = $request->attributes->get('staff');

        return $staff instanceof PlatformStaff ? $staff : null;
    }
}
