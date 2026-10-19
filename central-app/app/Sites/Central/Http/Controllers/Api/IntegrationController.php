<?php

namespace App\Sites\Central\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Models\Application;
use App\Modules\Integration\Support\EffectiveAccess;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Modules\Structure\Models\Position;
use App\Support\Tenancy\TenantContext;
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

    /**
     * The organization's branches: products keep stock, sales and role
     * scopes per branch and need names for the ids in effective access.
     */
    public function branches(Request $request, string $organization, TenantContext $tenant): JsonResponse
    {
        $record = $this->visibleOrganization($this->application($request), $organization, allowSlug: false);

        $branches = $tenant->run($record, fn () => Branch::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'city', 'status'])
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'city' => $branch->city,
                'status' => $branch->status,
            ])
            ->all());

        return response()->json(['data' => $branches])
            ->header('Cache-Control', 'private, max-age='.EffectiveAccess::TTL_SECONDS);
    }

    /**
     * The organization's departments: products that keep records per
     * department (HR) need the names for the ids in effective access. A null
     * branch_id is a company-wide department.
     */
    public function departments(Request $request, string $organization, TenantContext $tenant): JsonResponse
    {
        $record = $this->visibleOrganization($this->application($request), $organization, allowSlug: false);

        $departments = $tenant->run($record, fn () => Department::query()
            ->orderBy('name')
            ->get(['id', 'branch_id', 'parent_department_id', 'name', 'code', 'status'])
            ->map(fn (Department $department) => [
                'id' => $department->id,
                'branch_id' => $department->branch_id,
                'parent_department_id' => $department->parent_department_id,
                'name' => $department->name,
                'code' => $department->code,
                'status' => $department->status,
            ])
            ->all());

        return response()->json(['data' => $departments])
            ->header('Cache-Control', 'private, max-age='.EffectiveAccess::TTL_SECONDS);
    }

    /**
     * The organization's positions (job titles). Business structure only:
     * a position grants no permissions.
     */
    public function positions(Request $request, string $organization, TenantContext $tenant): JsonResponse
    {
        $record = $this->visibleOrganization($this->application($request), $organization, allowSlug: false);

        $positions = $tenant->run($record, fn () => Position::query()
            ->orderBy('title')
            ->get(['id', 'department_id', 'title', 'code', 'status'])
            ->map(fn (Position $position) => [
                'id' => $position->id,
                'department_id' => $position->department_id,
                'title' => $position->title,
                'code' => $position->code,
                'status' => $position->status,
            ])
            ->all());

        return response()->json(['data' => $positions])
            ->header('Cache-Control', 'private, max-age='.EffectiveAccess::TTL_SECONDS);
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
