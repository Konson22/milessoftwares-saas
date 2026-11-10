import { Form } from '@inertiajs/react';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Select } from '@/components/field';
import type { Option } from '@/types';

/**
 * Search and filters for the marketplace lists: a plain GET of this page, so
 * a filtered list can be bookmarked.
 */
export function MarketplaceFilters({
    filters,
    sectors,
    statuses,
}: {
    filters: {
        q: string | null;
        sector: string | null;
        country: string | null;
        status?: string | null;
        currency?: string | null;
    };
    sectors: Option[];
    /** For projects only. */
    statuses?: Option[];
}) {
    return (
        <Form
            method="get"
            options={{ preserveScroll: true, preserveState: true }}
            className="mb-4 flex flex-wrap items-end gap-2"
        >
            <Input
                name="q"
                type="search"
                placeholder="Search by name or words"
                aria-label="Search"
                defaultValue={filters.q ?? ''}
                className="w-56"
            />
            <Select
                name="sector"
                aria-label="Sector"
                placeholder="Any sector"
                options={sectors}
                defaultValue={filters.sector ?? ''}
                className="h-9 w-56"
            />
            <Input
                name="country"
                aria-label="Country code"
                placeholder="Country (e.g. SS)"
                maxLength={2}
                defaultValue={filters.country ?? ''}
                className="w-40 uppercase"
            />
            {statuses && (
                <>
                    <Select
                        name="status"
                        aria-label="Funding status"
                        placeholder="Any funding status"
                        options={statuses}
                        defaultValue={filters.status ?? ''}
                        className="h-9 w-48"
                    />
                    <Input
                        name="currency"
                        aria-label="Currency"
                        placeholder="Currency"
                        maxLength={3}
                        defaultValue={filters.currency ?? ''}
                        className="w-28 uppercase"
                    />
                </>
            )}
            <Button type="submit" variant="outline">
                Filter
            </Button>
        </Form>
    );
}
