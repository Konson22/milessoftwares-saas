<?php

namespace App\Sites\Public\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Licences\Actions\ActivateLicence;
use App\Modules\Licences\Actions\LicenceDenied;
use App\Modules\Licences\Notifications\ActivationCodeSent;
use App\Modules\Licences\Support\LicenceCodes;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Activating a PC that has no internet, from a phone: the app shows a QR code
 * that opens this page with its device code and pharmacy ID filled in; the
 * customer adds their licence key and gets the activation code to copy,
 * download (to carry on a USB stick) or receive by email.
 */
class ActivationController extends Controller
{
    public function show(Request $request): Response
    {
        $prefill = fn (string $field, int $length) => LicenceCodes::format((string) $request->query($field, ''), $length);

        return Inertia::render('public/activate', [
            'device' => $prefill('device', 12),
            'pharmacy' => $prefill('pharmacy', 8),
            'name' => mb_substr(trim((string) $request->query('name', '')), 0, 120) ?: null,
            'result' => $request->session()->get('activation'),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'activate'),
        ]);
    }

    public function store(Request $request, ActivateLicence $activate): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'device' => ['required', 'string', 'max:32'],
            'pharmacy' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        try {
            $result = $activate->handle($data['key'], $data['device'], $data['pharmacy'], $data['name'] ?? null, 'web', ['ip' => $request->ip()]);
        } catch (LicenceDenied $denied) {
            throw ValidationException::withMessages([$denied->field => $denied->getMessage()]);
        }

        $activation = $result['activation'];
        if (! empty($data['email'])) {
            Notification::route('mail', $data['email'])->notify(new ActivationCodeSent($result['code'], $activation->device_code, $activation->pharmacy_name));
        }

        return redirect()->route('marketing.activate', [
            'device' => $activation->device_code,
            'pharmacy' => $activation->pharmacy_id,
        ])->with('activation', [
            'code' => $result['code'],
            'device' => $activation->device_code,
            'pharmacy' => $activation->pharmacy_id,
            'expiresAt' => $activation->expires_at?->toIso8601String(),
            'emailedTo' => $data['email'] ?? null,
        ]);
    }
}
