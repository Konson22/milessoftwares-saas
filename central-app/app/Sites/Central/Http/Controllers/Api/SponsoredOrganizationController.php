<?php

namespace App\Sites\Central\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Organizations\Actions\SponsoredOrganizations;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A product backend creates a workspace for another organization on behalf of
 * one of its own (Projects: a donor for its grantee). Allowed only to the
 * applications in platform.sponsoring_applications, for an organization
 * entitled to the calling application, by one of its active members.
 */
class SponsoredOrganizationController extends Controller
{
    public function __construct(private readonly SponsoredOrganizations $sponsored) {}

    public function store(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $validated = $request->validate([
            'sponsor_organization_id' => ['required', 'uuid'],
            'sponsor_user_id' => ['required', 'uuid'],
            'display_name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:63'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:255'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'default_currency' => ['required', 'string', 'size:3', 'alpha'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $sponsor = $this->sponsor($application, $validated['sponsor_organization_id']);
        $by = User::query()->whereKey($validated['sponsor_user_id'])->first();
        abort_if($by === null, 404);

        $claim = $this->sponsored->create($application, $sponsor, $by, $validated)['claim'];

        return response()->json($this->present($claim), 201);
    }

    public function resend(Request $request, string $claim): JsonResponse
    {
        [$claim, $by] = $this->claim($request, $claim);
        $this->sponsored->resend($claim, $by);

        return response()->json($this->present($claim->refresh()));
    }

    public function destroy(Request $request, string $claim): Response
    {
        [$claim, $by] = $this->claim($request, $claim);
        $this->sponsored->cancel($claim, $by);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrganizationClaim $claim): array
    {
        $claim->loadMissing('organization.primarySlug');

        return [
            'claim_id' => $claim->id,
            'organization' => [
                'id' => $claim->organization_id,
                'slug' => $claim->organization->primarySlug?->slug,
                'display_name' => $claim->organization->display_name,
            ],
            'status' => $claim->effectiveStatus(),
            'expires_at' => $claim->expires_at->toIso8601String(),
        ];
    }

    /**
     * @return array{0: OrganizationClaim, 1: User}
     */
    private function claim(Request $request, string $id): array
    {
        $application = $this->application($request);
        $validated = $request->validate([
            'sponsor_organization_id' => ['required', 'uuid'],
            'sponsor_user_id' => ['required', 'uuid'],
        ]);
        $sponsor = $this->sponsor($application, $validated['sponsor_organization_id']);

        $claim = OrganizationClaim::query()->whereKey($id)
            ->where('sponsor_organization_id', $sponsor->id)
            ->where('application_id', $application->id)
            ->first();
        $by = User::query()->whereKey($validated['sponsor_user_id'])->first();
        abort_if($claim === null || $by === null, 404);

        return [$claim, $by];
    }

    private function sponsor(Application $application, string $id): Organization
    {
        // An active organization whose entitlement is current: a lapsed
        // trial or a cancelled subscription sponsors nothing.
        $sponsor = Organization::query()->whereKey($id)->where('status', OrganizationStatus::Active)->first();
        $entitled = $sponsor !== null && Entitlement::acrossTenants()->current()
            ->where('organization_id', $sponsor->id)
            ->where('application_id', $application->id)
            ->exists();
        abort_unless($entitled, 404);

        return $sponsor;
    }

    private function application(Request $request): Application
    {
        $application = $request->attributes->get('api_application');
        abort_unless($application instanceof Application, 403);
        abort_unless(in_array($application->code, (array) config('platform.sponsoring_applications', []), true), 403);

        return $application;
    }
}
