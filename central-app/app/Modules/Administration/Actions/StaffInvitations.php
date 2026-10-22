<?php

namespace App\Modules\Administration\Actions;

use App\Models\User;
use App\Modules\Administration\Enums\PlatformPermission;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Administration\Models\StaffInvitation;
use App\Modules\Administration\Notifications\StaffInvitationSent;
use App\Modules\Administration\Support\PlatformRoleCatalog;
use App\Modules\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invitations to the platform staff. The admin host requires two-factor
 * authentication, which nobody can set up there before they can sign in;
 * the emailed link is how a new staff member chooses a password (or proves
 * the one they have) and sets it up. Staff membership and the role are
 * only given once they have.
 */
final class StaffInvitations
{
    public const VALID_DAYS = 7;

    public function __construct(
        private readonly StaffDirectory $directory,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Sends a new invitation; an earlier pending one to the same address
     * stops working.
     *
     * @throws StaffChangeDenied
     */
    public function invite(string $email, ?string $name, ?string $role, PlatformStaff $inviter): StaffInvitation
    {
        $email = mb_strtolower(trim($email));

        if ($role !== null && ! array_key_exists($role, PlatformRoleCatalog::roles())) {
            throw new StaffChangeDenied('Unknown role.');
        }

        if ($this->isSetUpStaff($email)) {
            throw new StaffChangeDenied('They are already staff with two-factor authentication: they can sign in now.');
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($email, $name, $role, $inviter, $token): StaffInvitation {
            $this->pendingFor($email)->update(['revoked_at' => now()]);

            $invitation = new StaffInvitation;
            $invitation->forceFill([
                'email' => $email,
                'name' => $name === null || trim($name) === '' ? null : trim($name),
                'role' => $role,
                'token_hash' => StaffInvitation::hashToken($token),
                'invited_by_staff_id' => $inviter->getKey(),
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ])->save();

            return $invitation;
        });

        $this->record('staff.invited', $invitation, $inviter, ['role' => $role]);
        $this->send($invitation, $inviter, $token);

        return $invitation;
    }

    /** A fresh link for a pending or expired invitation; the old one stops working. */
    public function resend(StaffInvitation $invitation, PlatformStaff $actor): void
    {
        if (in_array($invitation->status(), ['accepted', 'revoked'], true)) {
            throw new StaffChangeDenied('This invitation is closed. Send a new one.');
        }

        $token = Str::random(48);
        $invitation->forceFill([
            'token_hash' => StaffInvitation::hashToken($token),
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ])->save();

        $this->record('staff.invitation_resent', $invitation, $actor);
        $this->send($invitation, $actor, $token);
    }

    public function revoke(StaffInvitation $invitation, PlatformStaff $actor): void
    {
        if ($invitation->status() !== 'pending' && $invitation->status() !== 'expired') {
            return;
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        $this->record('staff.invitation_revoked', $invitation, $actor);
    }

    /** The invitation a link points to, while it can still be used. */
    public function findPending(string $token): ?StaffInvitation
    {
        $invitation = StaffInvitation::query()->where('token_hash', StaffInvitation::hashToken($token))->first();

        return $invitation?->status() === 'pending' ? $invitation : null;
    }

    /**
     * Makes the user staff, with the invited role. Called once they hold a
     * password and confirmed two-factor authentication. The inviter must
     * still be allowed to manage staff, or the invitation lapses.
     *
     * @throws StaffChangeDenied
     */
    public function accept(StaffInvitation $invitation, User $user): PlatformStaff
    {
        if ($user->two_factor_confirmed_at === null) {
            throw new StaffChangeDenied('Two-factor authentication must be set up first.');
        }

        return DB::transaction(function () use ($invitation, $user): PlatformStaff {
            $locked = StaffInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status() !== 'pending' || mb_strtolower($user->email) !== $locked->email) {
                throw new StaffChangeDenied('This invitation is no longer valid.');
            }

            $inviter = $locked->invitedBy;

            if ($inviter === null || ! $inviter->isActive() || ! $inviter->hasPermission(PlatformPermission::StaffManage)) {
                throw new StaffChangeDenied('Whoever invited you can no longer add staff. Ask for a new invitation.');
            }

            $staff = $this->directory->add($user, $inviter);

            if ($locked->role !== null && ! $staff->is($inviter)) {
                $this->directory->grantRole($staff, $locked->role, $inviter);
            }

            $locked->forceFill(['accepted_at' => now()])->save();
            $this->audit->record('staff.invitation_accepted', $locked, ['email' => $locked->email, 'staff_user_id' => $user->id], actor: $user, actorType: 'staff');

            return $staff;
        });
    }

    /**
     * Pending invitations, newest first, for the staff page.
     *
     * @return list<array{id: string, email: string, name: string|null, role: string|null, status: string, expiresAt: string}>
     */
    public function open(): array
    {
        return array_values(StaffInvitation::query()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest()
            ->get()
            ->map(fn (StaffInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'name' => $invitation->name,
                'role' => $invitation->role,
                'status' => $invitation->status(),
                'expiresAt' => $invitation->expires_at->toDateString(),
            ])
            ->all());
    }

    /** Active staff who can already sign in need no invitation. */
    private function isSetUpStaff(string $email): bool
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null || $user->two_factor_confirmed_at === null) {
            return false;
        }

        return PlatformStaff::query()->where('user_id', $user->getKey())->where('status', 'active')->exists();
    }

    /**
     * @return Builder<StaffInvitation>
     */
    private function pendingFor(string $email)
    {
        return StaffInvitation::query()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at');
    }

    private function send(StaffInvitation $invitation, PlatformStaff $from, string $token): void
    {
        Notification::route('mail', $invitation->email)->notify(new StaffInvitationSent(
            $from->user->name,
            $token,
            $invitation->expires_at,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, StaffInvitation $invitation, PlatformStaff $actor, array $context = []): void
    {
        $this->audit->record($action, $invitation, ['email' => $invitation->email, ...$context], actor: $actor->user, actorType: 'staff');
    }
}
