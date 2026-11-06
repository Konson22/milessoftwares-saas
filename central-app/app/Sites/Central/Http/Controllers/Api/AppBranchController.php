<?php

namespace App\Sites\Central\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Applications\Models\Application;
use App\Modules\Audit\AuditLogger;
use App\Modules\Authorization\Support\WorkspaceAbilities;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Sites\Central\Http\Controllers\Api\Concerns\RunsAsWorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A branch from an application's own app (the mobile counter): the workspace's
 * "Structure" page, for adding one. Same rule as the web: owners and members
 * who manage the structure. The app asks for a name; the code the web asks
 * for comes from it unless one is given.
 */
class AppBranchController extends Controller
{
    use RunsAsWorkspaceMember;

    public function store(Request $request, string $organization, AuditLogger $audit): JsonResponse
    {
        return $this->asMember(
            $request,
            $organization,
            fn (WorkspaceAbilities $abilities, OrganizationMembership $membership): bool => $abilities->manageStructure($membership),
            function (OrganizationMembership $membership, Application $application, Organization $workspace) use ($request, $audit): JsonResponse {
                $validated = $request->validate([
                    'name' => ['required', 'string', 'max:255'],
                    'code' => ['nullable', 'string', 'max:32', 'alpha_dash'],
                    'address' => ['nullable', 'string', 'max:1000'],
                    'city' => ['nullable', 'string', 'max:128'],
                ]);

                $branch = Branch::query()->create([
                    ...$validated,
                    'code' => $this->code($validated['code'] ?? null, $validated['name']),
                    'country_code' => $workspace->country_code,
                ]);

                /** @var User $user */
                $user = $request->user();
                $audit->record('branch.created', $branch, ['changes' => $branch->getAttributes()], actor: $user, organizationId: $workspace->id);

                return response()->json(['branch' => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'city' => $branch->city,
                ]], 201);
            },
        );
    }

    /** Unique in the workspace, whose archived branches keep theirs: "JUBA-TOWN", then "JUBA-TOWN-2". */
    private function code(?string $wanted, string $name): string
    {
        $base = Str::upper($wanted ?: Str::slug($name)) ?: 'BRANCH';
        $base = rtrim(Str::limit($base, 28, ''), '-');

        $taken = Branch::query()->pluck('code')->map(fn (string $code) => Str::upper($code))->all();
        $code = $base;
        for ($n = 2; in_array($code, $taken, true); $n++) {
            $code = "{$base}-{$n}";
        }

        return $code;
    }
}
