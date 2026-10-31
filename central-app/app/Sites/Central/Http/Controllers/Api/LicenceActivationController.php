<?php

namespace App\Sites\Central\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Licences\Actions\ActivateLicence;
use App\Modules\Licences\Actions\LicenceDenied;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The offline desktop pharmacy activating with a licence key: the app sends
 * the key with its device code and pharmacy ID, and keeps the signed code it
 * gets back. No sign-in: the key is the proof of purchase.
 */
class LicenceActivationController extends Controller
{
    public function store(Request $request, ActivateLicence $activate): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'device' => ['required', 'string', 'max:32'],
            'pharmacy' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $result = $activate->handle(
                $data['key'],
                $data['device'],
                $data['pharmacy'],
                $data['name'] ?? null,
                'app',
                ['ip' => $request->ip(), 'app_version' => $data['app_version'] ?? null],
            );
        } catch (LicenceDenied $denied) {
            return response()->json([
                'message' => $denied->getMessage(),
                'reason' => $denied->reason,
                'errors' => [$denied->field => [$denied->getMessage()]],
            ], 422);
        }

        return response()->json([
            'code' => $result['code'],
            'expires_at' => $result['activation']->expires_at?->toIso8601String(),
        ]);
    }
}
