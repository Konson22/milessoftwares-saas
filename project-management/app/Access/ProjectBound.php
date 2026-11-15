<?php

namespace App\Access;

use Illuminate\Database\Eloquent\Model;

/**
 * A record found only by those who may see its project
 * (App\Access\VisibleThroughProject).
 */
interface ProjectBound
{
    /**
     * The column pointing at the parent record, and the parent's class.
     *
     * @return array{0: string, 1: class-string<Model>}
     */
    public function visibleThrough(): array;
}
