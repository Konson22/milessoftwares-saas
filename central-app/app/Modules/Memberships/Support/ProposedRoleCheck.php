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
 * Separation of duties for the roles proposed while inviting or adding
 * someone. Asked of the administrator up front, like on the access page: a
 * role can conflict with itself (a pharmacy manager sells and voids) or with
 * another role proposed with it, and nobody is there to acknowledge it when
 * the roles are finally given.
 *
 * @phpstan-import-type ProposedRole from ProposedAccess
 */
final class ProposedRoleCheck
{
    public function __construct(private readonly AssignRole $assign) {}

    /**
     * Whether the roles raise warnings the administrator has acknowledged.
     *
     * @param  list<ProposedRole>  $proposed
     *
     * @throws SeparationOfDutiesWarning when they warn and they have not
     * @throws MembershipActionDenied when the catalog blocks one
     */
    public function separationOfDuties(array $proposed, bool $acknowledged): bool
    {
        $roles = ApplicationRole::query()
            ->with('application')
            ->whereIn('id', array_column($proposed, 'role_id'))
            ->get()
            ->keyBy('id');
        $warnings = [];

        foreach ($proposed as $proposal) {
            $role = $roles->get($proposal['role_id']);

            if ($role === null) {
                continue;
            }

            try {
                $warnings = [...$warnings, ...$this->assign->separationOfDutiesWarnings($role, Scope::fromInput($proposal['scope']), alongside: $roles->all())];
            } catch (AssignmentDenied|InvalidArgumentException $e) {
                throw new MembershipActionDenied($e->getMessage());
            }
        }

        $warnings = array_values(array_unique($warnings));

        if ($warnings !== [] && ! $acknowledged) {
            throw new SeparationOfDutiesWarning($warnings);
        }

        return $warnings !== [];
    }
}
