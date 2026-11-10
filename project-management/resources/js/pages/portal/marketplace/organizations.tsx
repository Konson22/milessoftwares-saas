import { Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { MarketplaceFilters } from '@/components/marketplace-filters';
import { show, logo } from '@/routes/marketplace/organizations';
import type { Option } from '@/types';

type Organization = {
    slug: string;
    name: string;
    summary: string | null;
    countryCode: string | null;
    location: string | null;
    sectors: string[];
    hasLogo: boolean;
    listings: number;
};

/** Organizations that published a profile in the funding marketplace. */
export default function MarketplaceOrganizations({
    organizations,
    filters,
    sectors,
}: {
    organizations: Organization[];
    filters: {
        q: string | null;
        sector: string | null;
        country: string | null;
    };
    sectors: Option[];
}) {
    return (
        <>
            <Head title="Discover organizations" />
            <PageHeader
                title="Discover organizations"
                description="Organizations that published a profile for donors. You see only what each one chose to share."
            />

            <MarketplaceFilters filters={filters} sectors={sectors} />

            {organizations.length === 0 ? (
                <EmptyState icon={Building2} title="No organizations match" />
            ) : (
                <div className="grid gap-3 md:grid-cols-2">
                    {organizations.map((organization) => (
                        <Link
                            key={organization.slug}
                            href={show({ slug: organization.slug })}
                            className="flex gap-4 rounded-lg border bg-card p-4 transition-colors hover:border-primary/50"
                        >
                            {organization.hasLogo ? (
                                <img
                                    src={logo.url({ slug: organization.slug })}
                                    alt=""
                                    className="size-12 shrink-0 rounded-md border object-contain"
                                />
                            ) : (
                                <span className="flex size-12 shrink-0 items-center justify-center rounded-md bg-muted text-primary">
                                    <Building2 className="size-5" aria-hidden />
                                </span>
                            )}
                            <span className="grid min-w-0 gap-1">
                                <span className="font-medium">
                                    {organization.name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {[
                                        organization.location,
                                        organization.countryCode,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    {organization.listings > 0 &&
                                        ` · ${organization.listings} seeking funding`}
                                </span>
                                {organization.summary && (
                                    <span className="line-clamp-2 text-sm text-muted-foreground">
                                        {organization.summary}
                                    </span>
                                )}
                                {organization.sectors.length > 0 && (
                                    <span className="text-xs text-muted-foreground">
                                        {organization.sectors.join(', ')}
                                    </span>
                                )}
                            </span>
                        </Link>
                    ))}
                </div>
            )}
        </>
    );
}
