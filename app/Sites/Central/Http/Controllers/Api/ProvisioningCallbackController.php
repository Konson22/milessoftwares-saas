<?php

namespace App\Sites\Central\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Models\Application;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Provisioning\Models\ProvisioningStep;
use App\Modules\Provisioning\Support\Provisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Async products report the outcome of a provisioning step here. Only the
 * application the step belongs to may complete it; reporting twice is
 * harmless.
 */
class ProvisioningCallbackController extends Controller
{
    public function complete(Request $request, string $step, TenantContext $tenant): JsonResponse
    {
        $application = $request->attributes->get('api_application');
        abort_unless($application instanceof Application, 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['succeeded', 'failed'])],
            'error' => ['nullable', 'string', 'max:1000'],
        ]);

        $record = ProvisioningStep::acrossTenants()->whereKey($step)->where('application_id', $application->id)->first();
        abort_if($record === null, 404);

        if (in_array($record->status, ['succeeded', 'failed'], true)) {
            return response()->json(['status' => $record->status]);
        }

        $organization = Organization::query()->findOrFail($record->organization_id);
        $tenant->run($organization, fn () => Provisioner::finish(
            ProvisioningStep::query()->with('run')->findOrFail($record->id),
            $validated['status'] === 'succeeded',
            $validated['error'] ?? null,
        ));

        return response()->json(['status' => $validated['status']]);
    }
}
