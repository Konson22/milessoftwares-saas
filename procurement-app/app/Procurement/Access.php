<?php

namespace App\Procurement;

use App\Procurement\Models\Requisition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * What a member reaches. Everything is already limited to their
 * organization (BelongsToTenant). Within it:
 *
 * - procurement.view: every procurement record;
 * - requisition approvers: every requisition (they decide them);
 * - requesters: their own requisitions, and how each one is progressing.
 *
 * A record outside this answers 404, never 403, so ids reveal nothing.
 */
final class Access
{
    public static function seesAll(): bool
    {
        return Gate::allows('procurement.view');
    }

    /**
     * @return Builder<Requisition>
     */
    public static function requisitions(): Builder
    {
        $query = Requisition::query();

        if (self::seesAll() || Gate::allows('procurement.requisitions.approve')) {
            return $query;
        }

        return $query->where('requester_id', Auth::id());
    }

    public static function requisition(string $id): Requisition
    {
        return self::requisitions()->findOrFail($id);
    }
}
