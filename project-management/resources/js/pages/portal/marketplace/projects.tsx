import { Head } from '@inertiajs/react';
import { Megaphone } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { ListingCard } from '@/components/marketplace';
import type { ListingCardData } from '@/components/marketplace';
import { MarketplaceFilters } from '@/components/marketplace-filters';
import type { Option } from '@/types';

/** Projects organizations published as seeking funding. */
export default function MarketplaceProjects({
    listings,
    filters,
    sectors,
    fundingStatuses,
}: {
    listings: ListingCardData[];
    filters: {
        q: string | null;
        sector: string | null;
        country: string | null;
        status: string | null;
        currency: string | null;
    };
    sectors: Option[];
    fundingStatuses: Option[];
}) {
    return (
        <>
            <Head title="Projects seeking funding" />
            <PageHeader
                title="Projects seeking funding"
                description="What each organization asks for and has confirmed, as it states it. Amounts are in each project's own currency."
            />

            <MarketplaceFilters
                filters={filters}
                sectors={sectors}
                statuses={fundingStatuses}
            />

            {listings.length === 0 ? (
                <EmptyState icon={Megaphone} title="No projects match" />
            ) : (
                <div className="grid gap-3 md:grid-cols-2">
                    {listings.map((listing) => (
                        <ListingCard key={listing.id} listing={listing} />
                    ))}
                </div>
            )}
        </>
    );
}
