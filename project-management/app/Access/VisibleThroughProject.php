<?php

namespace App\Access;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;

/**
 * For records that belong to a project (a project_id column) or to a record
 * that does: they are only found when the member may see that project
 * (App\Access\ProjectVisibility). Route binding, lists, searches, downloads
 * and counts all go through it, so a team-only project's tasks, expenses,
 * attachments and so on stay with its team.
 *
 * The model implements ProjectBound and names what it hangs off with
 * visibleThrough(): the column and the model class it points at (itself
 * narrowed the same way, down to the project). Records with no project (a
 * null column) are left alone: they are organization-wide by nature.
 *
 * @mixin Model
 */
trait VisibleThroughProject
{
    public static function bootVisibleThroughProject(): void
    {
        static::addGlobalScope(new VisibleThroughProjectScope);
    }

    /**
     * @return array{0: string, 1: class-string<Model>}
     */
    public function visibleThrough(): array
    {
        return ['project_id', Project::class];
    }
}
