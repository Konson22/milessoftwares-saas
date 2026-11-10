<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Project;
use App\Models\User;

/**
 * Writes a project's activity log: "Moved WEB-4 to Done".
 */
final class ActivityLog
{
    public static function record(Project $project, User $by, string $description): void
    {
        Activity::query()->create([
            'project_id' => $project->id,
            'user_id' => $by->id,
            'description' => mb_substr($description, 0, 255),
            'created_at' => now(),
        ]);
    }
}
