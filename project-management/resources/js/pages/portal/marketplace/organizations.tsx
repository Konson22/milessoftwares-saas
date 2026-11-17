import { Head, Link, router } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Button } from '@miles/ui/components/ui/button';
import { MarketplaceFilters } from '@/components/marketplace-filters';
import { store as inviteOrganization } from '@/routes/beneficiaries/organizations';
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

type Other = {
    id: string;
    slug: string;
    name: string;
    countryCode: string | null;
};

/**
 * Organizations that published a profile in the funding marketplace; and,
 * when searching by name, others on Tatook that let themselves be found.
 */
export default function MarketplaceOrganizations({
    organizations,
    others,
    canInvite,
    filters,
    sectors,
}: {
    organizations: Organization[];
    others: Other[];
    canInvite: boolean;
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
                description="Organizations that published a profile for donors, and any other on Tatook you search for by name. You see only what each one chose to share."
            />

            <MarketplaceFilters filters={filters} sectors={sectors} />

            {organizations.length === 0 && others.length === 0 ? (
                <EmptyState icon={Building2} title="No organizations match">
                    {filters.q
                        ? 'Check the spelling or try its workspace address. Not on Tatook yet? Invite the person who runs it by email from Beneficiaries.'
                        : 'Search by name to find any organization on Tatook, or browse those that published a profile here.'}
                </EmptyState>
            ) : organizations.length === 0 ? null : (
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

            {others.length > 0 && (
                <section className="mt-6 grid gap-2">
                    <h2 className="text-sm font-medium">Also on Tatook</h2>
                    <p className="text-sm text-muted-foreground">
                        No public profile yet: you see their name and country
                        only.
                    </p>
                    <ul className="divide-y rounded-lg border bg-card">
                        {others.map((other) => (
                            <li
                                key={other.id}
                                className="flex items-center justify-between gap-3 px-4 py-3"
                            >
                                <span className="min-w-0">
                                    <span className="font-medium">
                                        {other.name}
                                    </span>{' '}
                                    <span className="text-xs text-muted-foreground">
                                        {[other.slug, other.countryCode]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                                {canInvite && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                inviteOrganization.url(),
                                                {
                                                    organization_id: other.id,
                                                    label: 'implementer',
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Invite to partner
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    );
}
