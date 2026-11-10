<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Parts of the work structure are archived, never deleted: archived_at.
 *
 * @mixin Model
 */
trait Archivable
{
    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('archived_at'));
    }

    public function isArchived(): bool
    {
        return $this->getAttribute('archived_at') !== null;
    }
}
