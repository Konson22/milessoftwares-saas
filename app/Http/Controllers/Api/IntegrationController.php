<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Models\Application;
use App\Modules\Integration\Support\EffectiveAccess;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product-facing API on the auth host, authenticated as the calling
 * application (AuthenticateApplicationClient). An application only sees
 * organizations that are, or were, entitled to it; anything else is 404.
 */
class IntegrationController extends Controller
{
    /**
     * Resolve the tenant behind a workspace host: products know the slug from
     * the request host and need the immutable organization id.
     */
    public function organization(Request $request, string $organization): JsonResponse
    {
        $application = $this->application($request);
        $record = $this->visibleOrganization($application, $organization, allowSlug: true);

        return response()->json([
            'id' => $record->id,
            'slug' => $record->primarySlug?->slug,
            'display_name' => $record->display_name,
            'status' => $record->status->value,
            'country_code' => $record->country_code,
            'default_currency' => $record->default_currency,
            'timezone' => $record->timezone,
        ]);
    }

    public function effectiveAccess(Request $request, string $organization, string $subject, EffectiveAccess $access): JsonResponse
    {
        $application = $this->application($request);
        $record = $this->visibleOrganization($application, $organization, allowSlug: false);

        abort_unless(Str::isUuid($subject), 404);

        return response()->json($access->for($record, $subject, $application))
            ->header('Cache-Control', 'private, max-age='.EffectiveAccess::TTL_SECONDS);
    }

    private function application(Request $request): Application
    {
        $application = $request->attributes->get('api_application');
        abort_unless($application instanceof Application, 403);

        return $application;
    }

    private function visibleOrganization(Application $application, string $key, bool $allowSlug): Organization
    {
        $organization = match (true) {
            Str::isUuid($key) => Organization::query()->find($key),
            $allowSlug => OrganizationSlug::query()->where('slug', strtolower($key))->where('is_primary', true)->first()?->organization,
            default => null,
        };

        $everEntitled = $organization !== null && DB::table('entitlements')
            ->where('organization_id', $organization->id)
            ->where('application_id', $application->id)
            ->exists();

        abort_unless($everEntitled, 404);

        return $organization;
    }
}
