<?php

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Models\OrganizationSlug;
use App\Modules\Organizations\Support\SlugRules;

final class CheckSlugAvailability
{
    /**
     * Problems with a proposed slug; empty when it can be registered. Retired
     * slugs count as taken forever, so a name can never be handed to someone
     * else after a rename.
     *
     * @return list<string>
     */
    public function errors(string $slug): array
    {
        $errors = SlugRules::errors($slug);

        if ($errors !== []) {
            return $errors;
        }

        if (OrganizationSlug::query()->where('slug', SlugRules::normalize($slug))->exists()) {
            return ['This slug is already taken.'];
        }

        return [];
    }
}
