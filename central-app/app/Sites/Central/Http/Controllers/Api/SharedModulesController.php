<?php

namespace App\Sites\Central\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Models\Application;
use App\Modules\Integration\Support\EventTokenIssuer;
use App\Modules\Integration\Support\EventTokenRefused;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Event tokens for products sending business events to shared modules, and
 * health reports from any product or module.
 */
class SharedModulesController extends Controller
{
    public function eventToken(Request $request, EventTokenIssuer $issuer): JsonResponse
    {
        $source = $this->application($request);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'audience' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/'],
        ]);

        $organization = Organization::query()->find($request->string('organization_id')->toString());
        abort_if($organization === null, 404);

        try {
            return response()->json($issuer->issue($source, $organization, $validated['audience']));
        } catch (EventTokenRefused $e) {
            // Unknown organization and not-entitled look the same from outside.
            return response()->json(['error' => $e->reason, 'error_description' => $e->getMessage()], $e->reason === 'not_entitled' ? 404 : 422);
        }
    }

    public function health(Request $request): JsonResponse
    {
        $application = $this->application($request);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['ok', 'degraded', 'down'])],
            'components' => ['sometimes', 'array', 'max:50'],
            'components.*' => [Rule::in(['ok', 'degraded', 'down'])],
            'version' => ['nullable', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $previous = DB::table('application_health')->where('application_id', $application->id)->first();
        $changed = $previous === null || $previous->status !== $validated['status'];

        DB::table('application_health')->upsert([[
            'application_id' => $application->id,
            'status' => $validated['status'],
            'components' => json_encode($validated['components'] ?? new \stdClass, JSON_THROW_ON_ERROR),
            'version' => $validated['version'] ?? null,
            'message' => $validated['message'] ?? null,
            'reported_at' => now(),
            'status_since' => $changed ? now() : $previous->status_since,
        ]], ['application_id']);

        return response()->json(['recorded' => true], 202);
    }

    private function application(Request $request): Application
    {
        $application = $request->attributes->get('api_application');
        abort_unless($application instanceof Application, 403);

        return $application;
    }
}
