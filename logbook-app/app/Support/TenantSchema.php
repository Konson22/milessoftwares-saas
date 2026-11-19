<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * Tenant keys for migrations (docs/architecture.md §3): every tenant table
 * is unique on (organization_id, id), and a child points at its parent
 * through both columns, so a row can never reference another
 * organization's row, whatever the application code does.
 */
final class TenantSchema
{
    public static function key(Blueprint $table): void
    {
        $table->unique(['organization_id', 'id'], $table->getTable().'_tenant_key');
    }

    public static function foreign(Blueprint $table, string $column, string $parent): void
    {
        $table->foreign(['organization_id', $column], $table->getTable().'_'.$column.'_tenant_fk')
            ->references(['organization_id', 'id'])
            ->on($parent);
    }
}
