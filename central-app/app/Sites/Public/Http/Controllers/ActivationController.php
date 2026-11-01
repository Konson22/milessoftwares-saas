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
 * Activating a PC (or a restaurant's phone) that has no internet, from
 * another device: the app's QR code or shared link opens this page with its
 * device code and pharmacy (or restaurant) ID filled in; the
 * customer adds their licence key and gets the activation code to copy,
 * download (to carry on a USB stick) or receive by email.
 */
class ActivationController extends Controller
{
    public function show(Request $request): Response
    {
        $prefill = fn (string $field, int $length) => LicenceCodes::format((string) $request->query($field, ''), $length);

        return Inertia::render('public/activate', [
            'product' => $request->query('product') === 'restaurant' ? 'restaurant' : 'pharmacy',
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
            'product' => ['nullable', 'string', 'in:pharmacy,restaurant'],
            'device' => ['required', 'string', 'max:32'],
            'pharmacy' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        try {
            $result = $activate->handle($data['key'], $data['device'], $data['pharmacy'], $data['name'] ?? null, 'web', ['ip' => $request->ip()], product: $data['product'] ?? 'pharmacy');
        } catch (LicenceDenied $denied) {
            throw ValidationException::withMessages([$denied->field => $denied->getMessage()]);
        }

        $activation = $result['activation'];
        if (! empty($data['email'])) {
            Notification::route('mail', $data['email'])->notify(new ActivationCodeSent($result['code'], $activation->device_code, $activation->pharmacy_name, $data['product'] ?? 'pharmacy'));
        }

        return redirect()->route('marketing.activate', [
            'product' => $data['product'] ?? 'pharmacy',
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
