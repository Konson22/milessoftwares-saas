<?php

namespace App\Modules\Organizations\Actions;

use App\Models\User;
use App\Modules\Applications\Support\WorkspaceShell;
use App\Modules\Audit\AuditLogger;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Modules\Organizations\Support\SlugRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an organization to a new primary slug. The old slug is retired: its
 * host redirects for the grace period and the name is never released.
 * organization_id is unaffected, so nothing else changes.
 */
final class RenameOrganizationSlug
{
    public function __construct(
        private readonly CheckSlugAvailability $slugs,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Organization $organization, string $newSlug, User $actor): OrganizationSlug
    {
        $slug = SlugRules::normalize($newSlug);

        if (($errors = $this->slugs->errors($slug)) !== []) {
            throw ValidationException::withMessages(['slug' => $errors]);
        }

        $current = $organization->primarySlug()->first();
        $cooldownDays = (int) config('platform.slug.rename_cooldown_days', 30);

        // Counted from the last rename, so a new organization can fix a typo.
        $renamedRecently = $organization->slugs()
            ->whereNotNull('retired_at')
            ->where('retired_at', '>', now()->subDays($cooldownDays))
            ->exists();

        if ($renamedRecently) {
            throw ValidationException::withMessages([
                'slug' => ["The workspace address can be changed once every {$cooldownDays} days."],
            ]);
        }

        try {
            $primary = DB::transaction(function () use ($organization, $current, $slug): OrganizationSlug {
                if ($current !== null) {
                    $current->forceFill([
                        'is_primary' => false,
                        'retired_at' => now(),
                        'redirect_until' => now()->addDays((int) config('platform.slug.redirect_grace_days', 180)),
                    ])->save();
                }

                $primary = new OrganizationSlug;
                $primary->forceFill([
                    'organization_id' => $organization->getKey(),
                    'slug' => $slug,
                    'is_primary' => true,
                ])->save();

                WorkspaceShell::syncRedirectUri($organization);

                return $primary;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'organization_slugs_slug_unique')) {
                throw ValidationException::withMessages(['slug' => ['This slug is already taken.']]);
            }

            throw $e;
        }

        $this->audit->record(
            'organization.slug_changed',
            $organization,
            ['from' => $current?->slug, 'to' => $slug],
            actor: $actor,
            organizationId: $organization->getKey(),
        );

        return $primary;
    }
}
