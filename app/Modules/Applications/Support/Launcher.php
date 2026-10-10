<?php

namespace App\Modules\Applications\Support;

use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Enums\ApplicationStatus;
use App\Modules\Applications\Models\Application;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;

/**
 * The applications a member can open in their workspace: the organization
 * is entitled to it, it is released (available or beta) and has a workspace
 * path, and the member holds a role in it. Owners see every entitled
 * application. Runs inside the tenant context.
 */
final class Launcher
{
    public function __construct(private readonly AccessResolver $access) {}

    /**
     * @return list<array{code: string, name: string, description: string|null, href: string}>
     */
    public function for(OrganizationMembership $membership): array
    {
        $entitled = Entitlement::query()->current()->whereNull('feature_key')->pluck('application_id')->unique();

        $applications = Application::query()
            ->whereIn('id', $entitled)
            ->where('kind', '!=', ApplicationKind::Platform->value)
            ->whereIn('status', [ApplicationStatus::Available->value, ApplicationStatus::Beta->value])
            ->whereNotNull('route_prefix')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if (! $this->access->isOwner($membership)) {
            $withRoles = RoleAssignment::query()->live()->where('membership_id', $membership->getKey())->pluck('application_id');
            $applications = $applications->whereIn('id', $withRoles->all());
        }

        $workspaceUrl = $membership->organization->workspaceUrl();

        return array_values($applications->map(fn (Application $application) => [
            'code' => $application->code,
            'name' => $application->name,
            'description' => $application->description,
            'href' => rtrim((string) $workspaceUrl, '/').'/'.$application->route_prefix,
        ])->all());
    }
}
