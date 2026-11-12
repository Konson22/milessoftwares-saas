<?php

namespace App\Modules\Organizations\Actions;

use App\Models\User;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Support\TenantRedirectUris;
use App\Modules\Audit\AuditLogger;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Support\TrialRefused;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\MembershipLookup;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Enums\OrganizationType;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationClaim;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Modules\Organizations\Notifications\WorkspaceCreatedForYou;
use App\Modules\Organizations\Support\SlugRules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Workspaces one organization starts for another: a donor, from Projects,
 * creates the workspace of an organization it funds and names the person who
 * will run it. The workspace is made at once (its name and address are held)
 * but stays pending, with no owner and no members, until that person claims it
 * with the emailed link. Claiming makes them its owner, exactly as if they had
 * created it themselves; the donor never has a place in it.
 */
final class SponsoredOrganizations
{
    /** How long the link works; the sponsor can send a fresh one. */
    public const VALID_DAYS = 14;

    public function __construct(
        private readonly CheckSlugAvailability $slugs,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly MembershipLookup $memberships,
        private readonly AddFirstBranch $firstBranch,
        private readonly ManageEntitlements $entitlements,
    ) {}

    /**
     * @param  array{display_name: string, slug?: string|null, owner_name: string, owner_email: string, country_code: string, default_currency: string, timezone: string}  $data
     * @return array{claim: OrganizationClaim, token: string|null} token null when an earlier request made it
     *
     * @throws ValidationException
     */
    public function create(Application $application, Organization $sponsor, User $by, array $data): array
    {
        if ($sponsor->status !== OrganizationStatus::Active || $this->memberships->activeIn($by, $sponsor->id) === null) {
            throw ValidationException::withMessages(['sponsor' => 'Only an active member of an active organization can create a workspace for another.']);
        }

        // The same request again (a retry after a timeout): the workspace it
        // made, not a second one and a second email.
        $email = mb_strtolower(trim($data['owner_email']));
        $earlier = OrganizationClaim::query()
            ->where('sponsor_organization_id', $sponsor->getKey())
            ->where('application_id', $application->getKey())
            ->where('email', $email)
            ->where('status', OrganizationClaim::PENDING)
            ->where('expires_at', '>', now())
            ->whereHas('organization', fn ($query) => $query->where('display_name', $data['display_name']))
            ->first();
        if ($earlier !== null) {
            return ['claim' => $earlier, 'token' => null];
        }

        $slug = ($data['slug'] ?? null) !== null && $data['slug'] !== '' ? SlugRules::normalize($data['slug']) : $this->freeSlug($data['display_name']);
        if (($errors = $this->slugs->errors($slug)) !== []) {
            throw ValidationException::withMessages(['slug' => $errors]);
        }

        $token = Str::random(48);

        try {
            $claim = DB::transaction(function () use ($application, $sponsor, $by, $data, $slug, $token): OrganizationClaim {
                $organization = new Organization;
                $organization->forceFill([
                    'legal_name' => $data['display_name'],
                    'display_name' => $data['display_name'],
                    'organization_type' => OrganizationType::Organization,
                    'contact_email' => mb_strtolower(trim($data['owner_email'])),
                    'country_code' => strtoupper($data['country_code']),
                    'default_currency' => strtoupper($data['default_currency']),
                    'timezone' => $data['timezone'],
                    // Resolves nowhere until it has its owner.
                    'status' => OrganizationStatus::Pending,
                    'created_by_user_id' => $by->getKey(),
                ])->save();

                (new OrganizationSlug)->forceFill([
                    'organization_id' => $organization->getKey(),
                    'slug' => $slug,
                    'is_primary' => true,
                ])->save();

                $claim = new OrganizationClaim;
                $claim->forceFill([
                    'organization_id' => $organization->getKey(),
                    'email' => mb_strtolower(trim($data['owner_email'])),
                    'name' => trim($data['owner_name']),
                    'token_hash' => OrganizationClaim::hashToken($token),
                    'sponsor_organization_id' => $sponsor->getKey(),
                    'sponsor_user_id' => $by->getKey(),
                    'application_id' => $application->getKey(),
                    'status' => OrganizationClaim::PENDING,
                    'expires_at' => now()->addDays($this->days()),
                ])->save();

                $context = ['slug' => $slug, 'for' => $claim->email, 'application' => $application->code];
                $this->audit->record('organization.sponsored', $organization, [...$context, 'sponsor' => $sponsor->display_name], actor: $by, organizationId: $organization->getKey());
                $this->audit->record('organization.sponsored_other', $organization, [...$context, 'name' => $organization->display_name], actor: $by, organizationId: $sponsor->getKey());

                return $claim;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'organization_slugs_slug_unique')) {
                throw ValidationException::withMessages(['slug' => ['This address is already taken.']]);
            }

            throw $e;
        }

        $this->send($claim, $token, $by);

        return ['claim' => $claim, 'token' => $token];
    }

    /**
     * A fresh link (the old one stops working) and more time.
     */
    public function resend(OrganizationClaim $claim, User $by): void
    {
        abort_unless($claim->status === OrganizationClaim::PENDING, 409, 'This workspace is no longer waiting to be claimed.');

        $token = Str::random(48);
        $claim->forceFill(['token_hash' => OrganizationClaim::hashToken($token), 'expires_at' => now()->addDays($this->days())])->save();
        $this->audit->record('organization.claim_resent', $claim->organization, ['for' => $claim->email], actor: $by, organizationId: $claim->sponsor_organization_id);

        $this->send($claim, $token, $by);
    }

    /**
     * The sponsor withdraws a workspace nobody has claimed: it is removed and
     * its address freed.
     */
    public function cancel(OrganizationClaim $claim, User $by): void
    {
        abort_unless($claim->status === OrganizationClaim::PENDING, 409, 'This workspace has been claimed: it belongs to its owner now.');

        DB::transaction(function () use ($claim, $by) {
            $organization = Organization::query()->lockForUpdate()->findOrFail($claim->organization_id);
            abort_unless($organization->status === OrganizationStatus::Pending && $organization->owner_membership_id === null, 409);

            $this->audit->record('organization.sponsored_cancelled', $organization, ['name' => $organization->display_name, 'for' => $claim->email], actor: $by, organizationId: $claim->sponsor_organization_id);
            // The claim row goes with it (cascade); the audit entry stays with the sponsor.
            $organization->slugs()->delete();
            $organization->delete();
        });
    }

    public function findByToken(string $token): ?OrganizationClaim
    {
        return OrganizationClaim::query()->with(['organization.primarySlug', 'sponsor', 'application'])
            ->where('token_hash', OrganizationClaim::hashToken($token))->first();
    }

    /**
     * The person it was made for takes it: they become its owner, it gets its
     * first branch and the trial of the application that asked, and it opens.
     * Answers the claim with its organization and application.
     *
     * @throws ValidationException
     */
    public function claim(User $user, string $token): OrganizationClaim
    {
        $claim = DB::transaction(function () use ($user, $token): OrganizationClaim {
            $claim = OrganizationClaim::query()->with('application')->where('token_hash', OrganizationClaim::hashToken($token))->lockForUpdate()->first();

            $refuse = fn (string $message) => ValidationException::withMessages(['claim' => $message]);
            if ($claim === null || $claim->effectiveStatus() !== OrganizationClaim::PENDING) {
                throw $refuse(match ($claim?->effectiveStatus()) {
                    'expired' => 'This link has expired. Ask for a new one.',
                    OrganizationClaim::CLAIMED => 'This workspace has already been claimed.',
                    default => 'This link is no longer valid.',
                });
            }
            if (mb_strtolower($user->email) !== $claim->email) {
                throw $refuse('This workspace was created for a different email address.');
            }
            if ($user->email_verified_at === null) {
                throw $refuse('Verify your email address first.');
            }

            $organization = Organization::query()->lockForUpdate()->findOrFail($claim->organization_id);
            if ($organization->status !== OrganizationStatus::Pending || $organization->owner_membership_id !== null) {
                throw $refuse('This workspace already has an owner.');
            }

            $owner = $this->tenant->run($organization, function () use ($user): OrganizationMembership {
                $membership = new OrganizationMembership;
                $membership->forceFill([
                    'user_id' => $user->getKey(),
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ])->save();

                return $membership;
            });

            TenantRedirectUris::sync($organization);

            $organization->forceFill([
                'owner_membership_id' => $owner->getKey(),
                'status' => OrganizationStatus::Active,
                'activated_at' => now(),
            ])->save();
            $claim->forceFill([
                'status' => OrganizationClaim::CLAIMED,
                'claimed_at' => now(),
                'claimed_by_user_id' => $user->getKey(),
            ])->save();

            $this->firstBranch->handle($organization, $user);

            $this->audit->record('organization.claimed', $organization, ['sponsor' => $claim->sponsor_organization_id], actor: $user, organizationId: $organization->getKey());
            $this->audit->record('organization.sponsored_claimed', $organization, ['name' => $organization->display_name], actor: $user, organizationId: $claim->sponsor_organization_id);

            return $claim->setRelation('organization', $organization);
        });

        try {
            $this->entitlements->startTrial($claim->organization, $claim->application, $user);
        } catch (TrialRefused) {
            // The application offers no trial now: the owner chooses a plan in the workspace.
        }
        $claim->organization->refresh();

        return $claim;
    }

    /**
     * An address from the name, with a number when it is taken.
     */
    private function freeSlug(string $name): string
    {
        $base = Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')
            ->replaceMatches('/^[^a-z]+/', '')->limit((int) config('platform.slug.max_length', 32) - 4, '')->trim('-')->toString();
        $base = strlen($base) < (int) config('platform.slug.min_length', 4) ? "org-{$base}" : $base;
        $base = rtrim($base, '-');

        for ($number = 1; $number < 100; $number++) {
            $slug = $number === 1 ? $base : "{$base}-{$number}";
            if ($this->slugs->errors($slug) === []) {
                return $slug;
            }
        }

        return $base.'-'.Str::lower(Str::random(3));
    }

    private function days(): int
    {
        return self::VALID_DAYS;
    }

    private function send(OrganizationClaim $claim, string $token, User $by): void
    {
        $claim->loadMissing(['organization', 'sponsor']);

        Notification::route('mail', $claim->email)->notify(new WorkspaceCreatedForYou(
            name: $claim->name,
            organizationName: $claim->organization->display_name,
            sponsorName: $claim->sponsor->display_name,
            creatorName: $by->name,
            token: $token,
            expiresAt: $claim->expires_at,
        ));
    }
}
