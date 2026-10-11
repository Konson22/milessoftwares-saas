<?php

namespace App\Modules\Organizations\Actions;

use App\Models\User;
use App\Modules\Applications\Support\TenantRedirectUris;
use App\Modules\Audit\AuditLogger;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Modules\Organizations\Support\SlugRules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates an organization with its primary slug and the creator as owner.
 * The organization stays `pending` until every step has succeeded, so a
 * half-created organization never resolves as a workspace.
 */
final class CreateOrganization
{
    public function __construct(
        private readonly CheckSlugAvailability $slugs,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{display_name: string, legal_name: string, slug: string, business_type?: string|null, contact_email?: string|null, country_code: string, default_currency: string, timezone: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $creator, array $data): Organization
    {
        $slug = SlugRules::normalize($data['slug']);

        if (($errors = $this->slugs->errors($slug)) !== []) {
            throw ValidationException::withMessages(['slug' => $errors]);
        }

        try {
            $organization = DB::transaction(function () use ($creator, $data, $slug): Organization {
                $organization = new Organization;
                $organization->forceFill([
                    'legal_name' => $data['legal_name'],
                    'display_name' => $data['display_name'],
                    'business_type' => $data['business_type'] ?? null,
                    'contact_email' => $data['contact_email'] ?? null,
                    'country_code' => strtoupper($data['country_code']),
                    'default_currency' => strtoupper($data['default_currency']),
                    'timezone' => $data['timezone'],
                    'status' => OrganizationStatus::Pending,
                    'created_by_user_id' => $creator->getKey(),
                ])->save();

                $primary = new OrganizationSlug;
                $primary->forceFill([
                    'organization_id' => $organization->getKey(),
                    'slug' => $slug,
                    'is_primary' => true,
                ])->save();

                $owner = $this->tenant->run($organization, function () use ($creator): OrganizationMembership {
                    $membership = new OrganizationMembership;
                    $membership->forceFill([
                        'user_id' => $creator->getKey(),
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

                $this->audit->record(
                    'organization.created',
                    $organization,
                    ['slug' => $slug],
                    actor: $creator,
                    organizationId: $organization->getKey(),
                );

                return $organization;
            });
        } catch (QueryException $e) {
            // Two requests raced for the same slug; the unique index decided.
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'organization_slugs_slug_unique')) {
                throw ValidationException::withMessages(['slug' => ['This slug is already taken.']]);
            }

            throw $e;
        }

        return $organization;
    }
}
