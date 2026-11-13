<?php

namespace App\Sites\Central\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Authorization\Actions\SeparationOfDutiesWarning;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use App\Modules\Memberships\Actions\TemporaryAccounts;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A product backend adds a member with a username and a temporary password
 * the administrator chose on the product's own form (Petrol: a pump
 * attendant, who has no email). The same rules as workspace → Add directly:
 * the acting person must be an active member who may manage access, the
 * username is new on the platform, and the person chooses their own password
 * at first sign-in. One role of the calling application may be given with
 * it, at the organization or one branch.
 *
 * Allowed only to the applications in platform.member_account_applications,
 * for an organization whose entitlement to the calling application is current.
 */
class MemberAccountController extends Controller
{
    public function __construct(
        private readonly TemporaryAccounts $accounts,
        private readonly TenantContext $tenant,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $application = $request->attributes->get('api_application');
        abort_unless($application instanceof Application, 403);
        abort_unless(in_array($application->code, (array) config('platform.member_account_applications', []), true), 403);

        if (is_string($request->input('username'))) {
            $request->merge(['username' => mb_strtolower(trim($request->input('username')))]);
        }
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'actor_user_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'regex:'.TemporaryAccounts::USERNAME_PATTERN],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'role' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'uuid'],
        ], [
            'username.regex' => __('A username is 3 to 32 letters, digits, dots, dashes or underscores, starting with a letter or digit.'),
        ]);

        $organization = Organization::query()->whereKey($validated['organization_id'])->where('status', OrganizationStatus::Active)->first();
        $entitled = $organization !== null && Entitlement::acrossTenants()->current()
            ->where('organization_id', $organization->id)
            ->where('application_id', $application->id)
            ->exists();
        abort_unless($entitled, 404);

        return $this->tenant->run($organization, function () use ($application, $validated): JsonResponse {
            $actor = OrganizationMembership::query()
                ->where('user_id', $validated['actor_user_id'])
                ->where('status', MembershipStatus::Active)
                ->with('user')
                ->first();
            abort_if($actor === null, 403);

            $proposed = [];
            if (filled($validated['role'] ?? null)) {
                $role = ApplicationRole::query()
                    ->where('catalog_version_id', $application->current_catalog_version_id)
                    ->where('key', $validated['role'])
                    ->first();
                if ($role === null) {
                    throw ValidationException::withMessages(['role' => __('That role does not exist in this application.')]);
                }

                $branchId = $validated['branch_id'] ?? null;
                if ($branchId !== null && ! Branch::query()->whereKey($branchId)->exists()) {
                    throw ValidationException::withMessages(['branch_id' => __('That branch does not exist.')]);
                }

                $proposed[] = ['role_id' => $role->id, 'scope' => [
                    'scope_type' => $branchId === null ? 'organization' : 'branch',
                    'branch_id' => $branchId,
                    'department_id' => null,
                ]];
            }

            try {
                $result = $this->accounts->add($actor, $validated['name'], null, $proposed, false, $validated['username'], $validated['password']);
            } catch (MembershipActionDenied $e) {
                // Not allowed to add people is the actor's problem; the rest is the username's.
                $field = str_starts_with($e->getMessage(), 'You cannot') || str_contains($e->getMessage(), 'not accepting') ? 'actor_user_id' : 'username';

                throw ValidationException::withMessages([$field => $e->getMessage()]);
            } catch (SeparationOfDutiesWarning $warning) {
                throw ValidationException::withMessages(['role' => $warning->conflicts]);
            }

            /** @var User $user */
            $user = $result['membership']->user;

            return response()->json([
                'user_id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'expires_at' => $result['expiresAt']->toIso8601String(),
                'role_note' => $result['roleNote'],
            ], 201);
        });
    }
}
