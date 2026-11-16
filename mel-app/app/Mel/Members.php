<?php

namespace App\Mel;

use App\Models\WorkspaceMember;
use Illuminate\Validation\Rules\Exists;

/**
 * The organization's members, for M&E's people pickers (managers,
 * responsible people, programme access, field assignments): those who have
 * opened M&E in this organization (App\Http\Middleware\RememberMember).
 * Being listed grants nothing: access is the member's permissions and
 * grants.
 */
final class Members
{
    /**
     * Validation: the id is a member of the current organization.
     */
    public static function rule(): Exists
    {
        return WorkspaceMember::rule();
    }

    /**
     * People of the organization, for pickers: [{value, label}].
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return WorkspaceMember::options();
    }
}
