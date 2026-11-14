<?php

use Illuminate\Support\Collection;
use Miles\PlatformClient\Tenancy\CurrentTenant;

if (! function_exists('tenant_id')) {
    /**
     * The current organization's id, for validation rules that query tables
     * directly (exists, unique). Throws without a tenant.
     */
    function tenant_id(): string
    {
        return app(CurrentTenant::class)->require()->id;
    }
}

if (! function_exists('items')) {
    /**
     * An array or collection that validation or this app's own report data
     * guarantees (a list of rows, ids or cells), as a collection. Anything else is a
     * bug, so it fails loudly instead of reading as empty.
     *
     * @return Collection<array-key, mixed>
     */
    function items(mixed $value): Collection
    {
        if ($value instanceof Collection) {
            return $value;
        }

        if (! is_array($value)) {
            throw new LogicException('Expected an array, got '.get_debug_type($value).'.');
        }

        return collect($value);
    }
}
