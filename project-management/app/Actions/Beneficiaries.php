<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Enums\FunderKind;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\Partnership;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\InvitedAsBeneficiary;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Hosts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformRefused;
use Miles\PlatformClient\Api\PlatformUnavailable;

/**
 * A donor brings in the beneficiaries and implementers it works with. The donor
 * only writes the invitation: the organization is made, or an existing one
 * connected, when its admin accepts, so nothing ties to anyone without their
 * say and an email address gives nothing away. On accepting, the beneficiary
 * gets a funder record standing for the donor; it records grants against it and
 * chooses what each shares, exactly as for any funder.
 *
 * Or the donor creates their workspace (createWorkspace): the platform makes it
 * and holds it for the person named, who claims it and owns it. It connects to
 * the donor the first time it opens Projects (connectCreated), and shares
 * nothing until they turn it on, grant by grant.
 */
final class Beneficiaries
{
    /**
     * Runs a callback inside each beneficiary a donor is connected to and
     * joins what they return. A suspended beneficiary is left out.
     *
     * @template T
     *
     * @param  callable(Partnership): array<int, T>  $callback
     * @return Collection<int, T>
     */
    public static function across(Organization $donor, callable $callback): Collection
    {
        $partnerships = Partnership::query()->with('beneficiary')
            ->where('donor_organization_id', $donor->id)->whereNotNull('accepted_at')->orderBy('name')->get();
        $results = collect();

        foreach ($partnerships as $partnership) {
            $beneficiary = $partnership->beneficiary;
            if ($beneficiary?->isActive()) {
                $results = $results->merge(app(CurrentOrganization::class)->run($beneficiary, fn () => $callback($partnership)));
            }
        }

        return $results->values();
    }

    /**
     * The donor names the organization and the person who will run it, with the
     * role they hold there (a project manager, say). That person owns the new
     * organization, so it is they who invite its members, not the donor.
     *
     * @param  array{label: string, role?: string|null, name: string, email: string, currency: string, timezone: string, country_code?: string|null}  $details
     */
    public static function invite(Organization $donor, User $by, array $details): Partnership
    {
        $token = Str::random(48);
        $partnership = new Partnership([
            'label' => $details['label'],
            'role' => $details['role'] ?? null,
            'name' => $details['name'],
            'email' => mb_strtolower(trim($details['email'])),
            'currency' => $details['currency'],
            'timezone' => $details['timezone'],
            'country_code' => $details['country_code'] ?? null,
            'invited_by' => $by->id,
            'expires_at' => now()->addDays((int) config('access.invitation_days', 7)),
        ]);
        $partnership->forceFill(['donor_organization_id' => $donor->id, 'token_hash' => Partnership::hashToken($token)])->save();

        Audit::record('beneficiary.invited', $partnership, ['name' => $partnership->name, 'email' => $partnership->email], $donor->id, $by);
        self::send($partnership, $token, $donor, $by);

        return $partnership;
    }

    /**
     * The donor creates the organization's workspace instead of inviting it to
     * make one. The platform holds it, pending, for the person named, and
     * emails them the link that makes them its owner. The donor gets no place
     * in it.
     *
     * @param  array{label: string, name: string, slug?: string|null, owner_name: string, email: string, currency: string, timezone: string, country_code: string}  $details
     *
     * @throws ValidationException when the platform refuses (the address is taken...)
     */
    public static function createWorkspace(Organization $donor, User $by, array $details): Partnership
    {
        $email = mb_strtolower(trim($details['email']));
        try {
            $created = app(PlatformApi::class)->sponsorOrganization($donor->id, $by->id, [
                'display_name' => $details['name'],
                'slug' => ($details['slug'] ?? null) ?: null,
                'owner_name' => $details['owner_name'],
                'owner_email' => $email,
                'country_code' => $details['country_code'],
                'default_currency' => $details['currency'],
                'timezone' => $details['timezone'],
            ]);
        } catch (PlatformRefused $refused) {
            $errors = array_filter([
                'slug' => $refused->errors['slug'] ?? null,
                'email' => $refused->errors['owner_email'] ?? null,
                'name' => $refused->errors['display_name'] ?? null,
            ]);

            throw ValidationException::withMessages($errors === [] ? ['name' => $refused->getMessage()] : $errors);
        } catch (PlatformUnavailable) {
            // Safe to try again: the platform answers a repeat with the workspace it made.
            throw ValidationException::withMessages(['name' => 'The workspace could not be created just now. Try again in a minute.']);
        }

        // A retry the platform answered with the workspace made the first time.
        $existing = Partnership::query()->where('platform_claim_id', $created->claimId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $partnership = new Partnership([
            'label' => $details['label'],
            'name' => $created->displayName !== '' ? $created->displayName : $details['name'],
            'email' => $email,
            'currency' => $details['currency'],
            'timezone' => $details['timezone'],
            'country_code' => $details['country_code'],
            'invited_by' => $by->id,
            'expires_at' => $created->expiresAt !== '' ? $created->expiresAt : now()->addDays(14),
        ]);
        $partnership->forceFill([
            'donor_organization_id' => $donor->id,
            'platform_claim_id' => $created->claimId,
            'created_organization_id' => $created->organizationId,
            'created_slug' => $created->slug,
        ])->save();

        Audit::record('beneficiary.workspace_created', $partnership, ['name' => $partnership->name, 'email' => $email, 'slug' => $created->slug], $donor->id, $by);

        return $partnership;
    }

    /**
     * The first time a workspace a donor created opens Projects, it is
     * connected to that donor, by whoever claimed it (its owner). Its grants
     * stay its own: the donor sees only what it chooses to share.
     */
    public static function connectCreated(Organization $organization, User $by): void
    {
        $waiting = Partnership::query()->where('created_organization_id', $organization->id)
            ->whereNull('accepted_at')->whereNull('declined_at')->pluck('id')->map(fn ($id) => (string) $id);

        foreach ($waiting as $id) {
            DB::transaction(function () use ($id, $organization, $by) {
                $partnership = Partnership::query()->whereNull('accepted_at')->lockForUpdate()->find($id);
                if ($partnership === null || ! $organization->isActive()) {
                    return;
                }
                if (! self::connectable($partnership, $organization)) {
                    // Connected to this donor already (or the donor itself):
                    // nothing to wait for any more.
                    Audit::record('beneficiary.invitation_revoked', $partnership, ['email' => $partnership->email, 'reason' => 'already_connected'], $partnership->donor_organization_id, $by);
                    $partnership->delete();

                    return;
                }

                $funder = self::funderFor($partnership->donor, $organization);
                $partnership->forceFill([
                    'beneficiary_organization_id' => $organization->id,
                    'funder_id' => $funder->id,
                    'name' => $organization->name,
                    'accepted_at' => now(),
                    'accepted_by' => $by->id,
                ])->save();

                Audit::record('beneficiary.connected', $partnership, ['beneficiary' => $organization->name, 'created' => true], $partnership->donor_organization_id, $by);
                Audit::record('donor.connected', $partnership, ['donor' => $partnership->donor->name, 'created' => true], $organization->id, $by);
                self::tellDonorOrganization($partnership, "{$organization->name} claimed the workspace you created", $by);
            });
        }
    }

    /**
     * A fresh link (the old one stops working) and more time.
     */
    public static function resend(Partnership $partnership, User $by): void
    {
        abort_if($partnership->isDeclined(), 409, 'They declined this invitation.');
        if ($partnership->isCreated()) {
            try {
                $created = app(PlatformApi::class)->resendSponsoredOrganization((string) $partnership->platform_claim_id, $partnership->donor_organization_id, $by->id);
            } catch (PlatformRefused) {
                abort(409, 'They have claimed the workspace already. It connects the first time they open Projects.');
            } catch (NotVisible) {
                abort(409, 'This workspace is no longer waiting on the platform. Remove it here and create it again if needed.');
            } catch (PlatformUnavailable) {
                abort(503, 'The link could not be sent just now. Try again in a minute.');
            }
            $partnership->forceFill(['expires_at' => $created->expiresAt !== '' ? $created->expiresAt : now()->addDays(14)])->save();
            Audit::record('beneficiary.invitation_resent', $partnership, ['email' => $partnership->email, 'created' => true], $partnership->donor_organization_id, $by);

            return;
        }
        if ($partnership->invited_organization_id !== null) {
            // A direct invitation has no link: more time, and they hear again.
            $partnership->forceFill(['expires_at' => now()->addDays((int) config('access.invitation_days', 7))])->save();
            $organization = $partnership->invitedOrganization;
            if ($organization?->isActive()) {
                app(CurrentOrganization::class)->run($organization, fn () => Alerts::tell(
                    Alerts::holders('organization.manage'),
                    UserNotification::MESSAGE,
                    "Reminder: {$partnership->donor->name} invites you to partner",
                    null,
                    route('donor-connections.index', [], false),
                    $by,
                ));
            }

            return;
        }

        $token = Str::random(48);
        $partnership->forceFill([
            'token_hash' => Partnership::hashToken($token),
            'expires_at' => now()->addDays((int) config('access.invitation_days', 7)),
        ])->save();

        Audit::record('beneficiary.invitation_resent', $partnership, ['email' => $partnership->email], $partnership->donor_organization_id, $by);
        self::send($partnership, $token, $partnership->donor, $by);
    }

    public static function revoke(Partnership $partnership, User $by): void
    {
        // A workspace nobody has claimed is removed on the platform; one that
        // has been claimed is theirs, and only this connection goes.
        if ($partnership->isCreated()) {
            try {
                app(PlatformApi::class)->cancelSponsoredOrganization((string) $partnership->platform_claim_id, $partnership->donor_organization_id, $by->id);
            } catch (PlatformRefused|NotVisible) {
                // Claimed, or gone already.
            }
        }
        Audit::record('beneficiary.invitation_revoked', $partnership, ['email' => $partnership->email, 'organization' => $partnership->invited_organization_id], $partnership->donor_organization_id, $by);
        $partnership->delete();
    }

    /**
     * Invites an organization already on the platform, by name, as from the
     * marketplace. Reuses what is there: an existing connection, or an
     * invitation still waiting, is answered instead of a second one. Its
     * administrators accept or decline in the app; nothing connects before.
     *
     * @throws ValidationException
     */
    public static function inviteOrganization(Organization $donor, Organization $organization, User $by, string $label = 'implementer'): Partnership
    {
        if ($organization->id === $donor->id) {
            throw ValidationException::withMessages(['organization' => 'An organization cannot partner with itself.']);
        }
        if (! $organization->isActive()) {
            throw ValidationException::withMessages(['organization' => 'That organization is not active.']);
        }

        return DB::transaction(function () use ($donor, $organization, $by, $label) {
            $existing = Partnership::query()->where('donor_organization_id', $donor->id)
                ->where(fn ($query) => $query->where('beneficiary_organization_id', $organization->id)->orWhere('invited_organization_id', $organization->id))
                ->lockForUpdate()->get()
                ->first(fn (Partnership $partnership) => $partnership->isAccepted() || $partnership->isPending());
            if ($existing !== null) {
                return $existing;
            }

            $partnership = new Partnership([
                'label' => array_key_exists($label, Partnership::LABELS) ? $label : 'implementer',
                'name' => $organization->name,
                'email' => null,
                'currency' => $organization->currency,
                'timezone' => $organization->timezone,
                'country_code' => $organization->country_code,
                'invited_by' => $by->id,
                'expires_at' => now()->addDays((int) config('access.invitation_days', 7)),
            ]);
            $partnership->forceFill(['donor_organization_id' => $donor->id, 'invited_organization_id' => $organization->id])->save();

            Audit::record('beneficiary.invited', $partnership, ['organization' => $organization->name, 'direct' => true], $donor->id, $by);
            Audit::record('donor.invitation_received', $partnership, ['donor' => $donor->name], $organization->id, $by);
            app(CurrentOrganization::class)->run($organization, fn () => Alerts::tell(
                Alerts::holders('organization.manage'),
                UserNotification::MESSAGE,
                "{$donor->name} invites you to partner",
                'Accept or decline in Connected donors. Accepting shares nothing until you choose to, grant by grant.',
                route('donor-connections.index', [], false),
                $by,
            ));

            return $partnership;
        });
    }

    /**
     * An administrator of the invited organization accepts a direct invitation.
     * Connects it as it is: its owners, members and records stay its own.
     *
     * @throws ValidationException
     */
    public static function acceptDirect(Partnership $partnership, Organization $organization, User $by): Partnership
    {
        return DB::transaction(function () use ($partnership, $organization, $by) {
            $partnership = Partnership::query()->pendingFor($organization)->lockForUpdate()->findOrFail($partnership->id);
            $alreadyConnected = Partnership::query()->where('donor_organization_id', $partnership->donor_organization_id)
                ->where('beneficiary_organization_id', $organization->id)->exists();
            if ($alreadyConnected) {
                throw ValidationException::withMessages(['partnership' => 'You are already connected to this donor.']);
            }

            $funder = self::funderFor($partnership->donor, $organization);
            $partnership->forceFill([
                'beneficiary_organization_id' => $organization->id,
                'funder_id' => $funder->id,
                'name' => $organization->name,
                'accepted_at' => now(),
                'accepted_by' => $by->id,
            ])->save();

            Audit::record('beneficiary.connected', $partnership, ['beneficiary' => $organization->name, 'direct' => true], $partnership->donor_organization_id, $by);
            Audit::record('donor.connected', $partnership, ['donor' => $partnership->donor->name], $organization->id, $by);
            self::tellDonorOrganization($partnership, "{$organization->name} accepted your invitation", $by);

            return $partnership;
        });
    }

    /**
     * The invited side says no: by its administrators in the app, or through the
     * emailed link. The donor hears, and may invite again later.
     */
    public static function decline(Partnership $partnership, ?User $by): void
    {
        $partnership->forceFill(['declined_at' => now(), 'declined_by' => $by?->id, 'token_hash' => null])->save();

        Audit::record('beneficiary.invitation_declined', $partnership, ['name' => $partnership->name], $partnership->donor_organization_id, $by);
        if ($partnership->invited_organization_id !== null) {
            Audit::record('donor.invitation_declined', $partnership, ['donor' => $partnership->donor->name], $partnership->invited_organization_id, $by);
        }
        self::tellDonorOrganization($partnership, "{$partnership->name} declined your invitation", $by);
    }

    private static function tellDonorOrganization(Partnership $partnership, string $title, ?User $by): void
    {
        if (! $partnership->donor->isActive()) {
            return;
        }

        app(CurrentOrganization::class)->run($partnership->donor, fn () => Alerts::tell(
            Alerts::holders('organization.manage'),
            UserNotification::MESSAGE,
            $title,
            null,
            route('beneficiaries.index', [], false),
            $by,
        ));
    }

    /**
     * Whether this organization could be connected to the donor: any but the
     * donor itself, not connected to it already (one that funds others in turn
     * is fine).
     */
    public static function connectable(Partnership $partnership, Organization $organization): bool
    {
        return $organization->isActive()
            && $organization->id !== $partnership->donor_organization_id
            && ! Partnership::query()->where('donor_organization_id', $partnership->donor_organization_id)
                ->where('beneficiary_organization_id', $organization->id)->exists();
    }

    /**
     * Accepts an open invitation for $organization, the workspace $user
     * manages and accepts it in. The invitation names an address; whoever
     * manages the organization may accept it for them.
     */
    public static function accept(string $token, Organization $organization, User $user): Partnership
    {
        return DB::transaction(function () use ($token, $organization, $user) {
            $partnership = Partnership::query()->open()->where('token_hash', Partnership::hashToken($token))->lockForUpdate()->first();
            abort_if($partnership === null, 404);
            abort_unless(self::connectable($partnership, $organization), 409, 'This organization cannot be connected to this donor.');

            $funder = self::funderFor($partnership->donor, $organization);
            $partnership->forceFill([
                'beneficiary_organization_id' => $organization->id,
                'funder_id' => $funder->id,
                'name' => $organization->name,
                'token_hash' => null,
                'accepted_at' => now(),
                'accepted_by' => $user->id,
            ])->save();

            Audit::record('beneficiary.connected', $partnership, ['beneficiary' => $organization->name], $partnership->donor_organization_id, $user);
            Audit::record('donor.connected', $partnership, ['donor' => $partnership->donor->name], $organization->id, $user);

            app(CurrentOrganization::class)->run($partnership->donor, fn () => Alerts::tell(
                Alerts::holders('organization.manage'),
                UserNotification::MESSAGE,
                "{$organization->name} accepted your invitation",
                null,
                route('beneficiaries.show', ['partnership' => $partnership->id], false),
                $user,
            ));

            return $partnership;
        });
    }

    /**
     * Those at the donor who follow grants hear what a beneficiary sends them.
     */
    public static function tellDonor(Grant $grant, string $title, ?string $excerpt, User $by): void
    {
        $partnership = Partnership::query()->with('donor')->where('funder_id', $grant->funder_id)->first();
        if ($partnership === null || ! $partnership->donor->isActive()) {
            return;
        }

        app(CurrentOrganization::class)->run($partnership->donor, fn () => Alerts::tell(
            Alerts::holders('grants.view'),
            UserNotification::MESSAGE,
            $title,
            $excerpt,
            route('beneficiaries.grants.show', ['partnership' => $partnership->id, 'grant' => $grant->id], false),
            $by,
        ));
    }

    /**
     * A new funder record every time. Taking over one with the same name could
     * open another funder's shared grants to whoever named their account after it.
     */
    private static function funderFor(Organization $donor, Organization $beneficiary): Funder
    {
        return app(CurrentOrganization::class)->run($beneficiary, function () use ($donor) {
            $name = $donor->name;
            for ($number = 2; Funder::query()->where('name', $name)->exists(); $number++) {
                $name = "{$donor->name} (donor account {$number})";
            }

            return Funder::query()->create([
                'name' => $name,
                'kind' => FunderKind::Institutional,
                'country_code' => $donor->country_code,
                'active' => true,
            ]);
        });
    }

    private static function send(Partnership $partnership, string $token, Organization $donor, User $by): void
    {
        Notification::route('mail', $partnership->email)->notify(new InvitedAsBeneficiary(
            donor: $donor->name,
            inviter: $by->name,
            organization: $partnership->name,
            url: Hosts::organization($donor, route('partnerships.landing', ['token' => $token], false)),
            expiresAt: $partnership->expires_at,
        ));
    }
}
