<?php

namespace App\Actions;

use App\Access\Access;
use App\Audit\Audit;
use App\Enums\ExpenseStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\Expense;
use App\Models\FunderContact;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Partnership;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Support\Modules;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\Organization as PlatformOrganization;

/**
 * Organizations and their members as the platform has them. The platform
 * owns both (sign-up, members, roles: admin on the platform); this app keeps
 * a copy keyed by the platform's ids, refreshed whenever someone opens it
 * (App\Http\Middleware\EnterWorkspace), so its records can point at them and
 * jobs without a request (reminders, digests) know who holds what.
 */
final class Organizations
{
    /**
     * The organization as the platform has it now: made on first sight, its
     * name, address, country and timezone kept in step after that.
     */
    public static function mirror(PlatformOrganization $platform): Organization
    {
        $organization = Organization::query()->find($platform->id);
        $details = [
            'slug' => $platform->slug,
            'name' => $platform->displayName !== '' ? $platform->displayName : $platform->slug,
            'country_code' => $platform->countryCode,
            'timezone' => $platform->timezone ?? 'UTC',
        ];

        if ($organization === null) {
            $organization = new Organization;
            $organization->forceFill(['id' => $platform->id, 'currency' => $platform->defaultCurrency ?? 'USD']);
            // Signed up as a donor: the funding sections are on from the start
            // (Administration → Settings turns them off or on later).
            if ($platform->isDonor()) {
                $organization->forceFill(['capabilities' => [Organization::DONOR]]);
            }
            $organization->fill($details)->forceFill(['status' => Organization::ACTIVE])->save();
            Audit::record('organization.created', $organization, ['slug' => $organization->slug], $organization->id);

            return $organization;
        }

        // The currency stays what it was: every amount here is in it.
        $organization->fill($details)->forceFill(['status' => $platform->isActive() ? Organization::ACTIVE : Organization::SUSPENDED]);
        if ($organization->isDirty()) {
            $organization->save();
        }

        return $organization;
    }

    /**
     * The member as the platform has them now: their membership here, with a
     * copy of what they may do (for jobs and for "who can approve this"), and
     * any funder they were added as a contact for.
     */
    public static function enter(Organization $organization, User $user, Access $access): OrganizationMembership
    {
        return DB::transaction(function () use ($organization, $user, $access) {
            $membership = OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
            if ($membership === null) {
                $membership = new OrganizationMembership(['status' => OrganizationMembership::ACTIVE, 'joined_at' => now()]);
                $membership->forceFill(['organization_id' => $organization->id, 'user_id' => $user->id]);
            }
            $membership->forceFill([
                'status' => OrganizationMembership::ACTIVE,
                'is_owner' => $access->isOwner,
                'permissions' => $access->permissions,
                'roles' => $access->roles,
            ]);
            if ($membership->isDirty()) {
                $membership->save();
            }

            self::bindFunderContact($organization, $user);

            return $membership;
        });
    }

    /**
     * Adds someone as a contact for a funder. They need a place in the
     * workspace on the platform too (admin → Members, role "Funder contact");
     * the first time they open Projects here, they become the funder's contact.
     *
     * @throws ValidationException
     */
    public static function addFunderContact(Organization $organization, User $by, string $email, string $funderId): Invitation
    {
        $email = mb_strtolower(trim($email));
        $existing = User::findByEmail($email);
        if ($existing !== null && app(CurrentOrganization::class)->run($organization, fn () => FunderContact::query()->where('user_id', $existing->id)->exists())) {
            throw ValidationException::withMessages(['email' => "{$email} is already a contact here."]);
        }
        // Someone who works here is on the team, not the funder's side.
        $membership = $existing === null ? null : $organization->memberships()->where('user_id', $existing->id)->first();
        if ($membership !== null && ($membership->is_owner || array_diff($membership->permissions ?? [], [FunderContact::PERMISSION]) !== [])) {
            throw ValidationException::withMessages(['email' => "{$email} is on your team already."]);
        }

        return DB::transaction(function () use ($organization, $by, $email, $funderId) {
            $organization->invitations()->open()->where('email', $email)->update(['revoked_at' => now()]);

            $invitation = new Invitation([
                'email' => $email,
                'roles' => [],
                'funder_id' => $funderId,
                'invited_by' => $by->id,
                'expires_at' => now()->addDays((int) config('access.invitation_days', 30)),
            ]);
            $invitation->forceFill(['organization_id' => $organization->id, 'token_hash' => Invitation::hashToken(bin2hex(random_bytes(24)))])->save();
            Audit::record('member.invited', $invitation, ['email' => $email, 'funder_id' => $funderId], $organization->id, $by);

            // Someone already here becomes the contact at once.
            if (($user = User::findByEmail($email)) !== null && $organization->memberships()->where('user_id', $user->id)->exists()) {
                self::bindFunderContact($organization, $user);
            }

            return $invitation;
        });
    }

    public static function revoke(Invitation $invitation, User $by): void
    {
        $invitation->forceFill(['revoked_at' => now()])->save();
        Audit::record('invitation.revoked', $invitation, ['email' => $invitation->email], $invitation->organization_id, $by);
    }

    /**
     * Turns funding other organizations on or off. It stays on while anyone has
     * been invited: those partnerships would be left without a way to reach them.
     *
     * @throws ValidationException
     */
    public static function setFunding(Organization $organization, bool $funds, User $by): void
    {
        if ($funds === $organization->isDonor()) {
            return;
        }
        if (! $funds && Partnership::query()->where('donor_organization_id', $organization->id)->exists()) {
            throw ValidationException::withMessages(['funding' => 'Remove the organizations you fund first.']);
        }

        $others = array_values(array_diff($organization->capabilities ?? [], [Organization::DONOR]));
        $capabilities = $funds ? [...$others, Organization::DONOR] : $others;
        $organization->fill(['capabilities' => $capabilities === [] ? null : $capabilities])->save();

        Audit::record($funds ? 'organization.funding_enabled' : 'organization.funding_disabled', $organization, [], $organization->id, $by);
    }

    /**
     * Switches a part of the product on or off, keeping everything in it. Off is
     * refused while something outside depends on it.
     *
     * @throws ValidationException
     */
    public static function setModule(Organization $organization, string $module, bool $on, User $by): void
    {
        if ($on === $organization->hasModule($module)) {
            return;
        }
        if (! $on) {
            self::requireCanSwitchOff($organization, $module);
        }

        $disabled = array_values(array_diff($organization->disabled_modules ?? [], [$module]));
        if (! $on) {
            $disabled[] = $module;
        }
        $organization->fill(['disabled_modules' => $disabled === [] ? null : $disabled])->save();

        Audit::record($on ? 'organization.module_enabled' : 'organization.module_disabled', $organization, ['module' => $module], $organization->id, $by);
    }

    /**
     * @throws ValidationException
     */
    private static function requireCanSwitchOff(Organization $organization, string $module): void
    {
        if ($module === Modules::GRANTS) {
            if (Partnership::query()->where('beneficiary_organization_id', $organization->id)->exists()
                || FunderContact::query()->whereHas('funder', fn ($query) => $query->where('organization_id', $organization->id))->exists()) {
                throw ValidationException::withMessages(['module' => 'Donors follow your grants here. Disconnect them before turning this off.']);
            }
        }

        if ($module === Modules::PROCUREMENT) {
            $open = PurchaseRequest::query()->whereIn('status', [PurchaseRequestStatus::Submitted, PurchaseRequestStatus::Approved])->exists()
                || Expense::query()->whereIn('id', PurchaseOrder::query()->whereNotNull('expense_id')->select('expense_id'))
                    ->where('status', ExpenseStatus::Approved)->exists();
            if ($open) {
                throw ValidationException::withMessages(['module' => 'Finish the purchases still open before turning this off.']);
            }
        }
    }

    /**
     * An open "contact for a funder" for this address becomes theirs.
     */
    private static function bindFunderContact(Organization $organization, User $user): void
    {
        $invitation = $organization->invitations()->open()->whereNotNull('funder_id')->where('email', $user->email)->lockForUpdate()->first();
        if ($invitation === null) {
            return;
        }

        app(CurrentOrganization::class)->run($organization, fn () => FunderContact::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['funder_id' => $invitation->funder_id, 'created_by' => $invitation->invited_by],
        ));
        $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();
        Audit::record('invitation.accepted', $invitation, ['email' => $invitation->email], $organization->id, $user);
    }
}
