<?php

namespace App\Modules\Memberships\Support;

use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Authorization\Actions\AssignmentDenied;
use App\Modules\Authorization\Actions\AssignRole;
use App\Modules\Authorization\Actions\SeparationOfDutiesWarning;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Memberships\Actions\MembershipActionDenied;
use InvalidArgumentException;

/**
 * Separation of duties for a role proposed while inviting or adding someone.
 * Asked of the administrator up front, like on the access page: a role can
 * conflict with itself (a pharmacy manager sells and voids), and nobody is
 * there to acknowledge it when the role is finally given.
 */
final class ProposedRoleCheck
{
    public function __construct(private readonly AssignRole $assign) {}

    /**
     * Whether the role raises warnings the administrator has acknowledged.
     *
     * @param  array{role_id: string, scope: array{scope_type: string, branch_id?: string|null, department_id?: string|null}}  $proposed
     *
     * @throws SeparationOfDutiesWarning when it warns and they have not
     * @throws MembershipActionDenied when the catalog blocks it
     */
    public function separationOfDuties(array $proposed, bool $acknowledged): bool
    {
        $role = ApplicationRole::query()->with('application')->find($proposed['role_id']);

        if ($role === null) {
            return false;
        }

        try {
            $warnings = $this->assign->separationOfDutiesWarnings($role, Scope::fromInput($proposed['scope']));
        } catch (AssignmentDenied|InvalidArgumentException $e) {
            throw new MembershipActionDenied($e->getMessage());
        }

        if ($warnings !== [] && ! $acknowledged) {
            throw new SeparationOfDutiesWarning($warnings);
        }

        return $warnings !== [];
    }
}
