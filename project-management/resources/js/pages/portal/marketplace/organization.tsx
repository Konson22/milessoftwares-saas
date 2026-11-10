import { Form, Head } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { ListingCard } from '@/components/marketplace';
import type { ListingCardData } from '@/components/marketplace';
import { PublicDocuments } from '@/components/public-documents';
import { invite, logo } from '@/routes/marketplace/organizations';

type Organization = {
    slug: string;
    name: string;
    legalName: string | null;
    summary: string | null;
    description: string | null;
    mission: string | null;
    vision: string | null;
    countryCode: string | null;
    location: string | null;
    operatingAreas: string[];
    sectors: string[];
    registrationNumber: string | null;
    registrationAuthority: string | null;
    website: string | null;
    contactEmail: string | null;
    contactPhone: string | null;
    achievements: string | null;
    hasLogo: boolean;
    documents: { id: string; title: string }[];
    listings: ListingCardData[];
};

function Detail({ label, value }: { label: string; value: string | null }) {
    if (!value) {
        return null;
    }

    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="break-words">{value}</dd>
        </div>
    );
}

/** An organization's public profile, as it published it. */
export default function MarketplaceOrganization({
    organization,
    connection,
    canInvite,
}: {
    organization: Organization;
    connection: 'connected' | 'invited' | null;
    canInvite: boolean;
}) {
    return (
        <>
            <Head title={organization.name} />
            <div className="mb-6 flex items-center gap-4">
                {organization.hasLogo ? (
                    <img
                        src={logo.url({ slug: organization.slug })}
                        alt={`${organization.name} logo`}
                        className="size-16 rounded-md border object-contain"
                    />
                ) : (
                    <span className="flex size-16 items-center justify-center rounded-md bg-muted text-primary">
                        <Building2 className="size-6" aria-hidden />
                    </span>
                )}
                <PageHeader
                    title={organization.name}
                    description={organization.summary ?? undefined}
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="grid content-start gap-4 lg:col-span-2">
                    <Panel>
                        <PanelHeader title="About" />
                        <PanelBody className="grid gap-4 text-sm whitespace-pre-line">
                            {organization.description && (
                                <p>{organization.description}</p>
                            )}
                            {organization.mission && (
                                <div>
                                    <p className="mb-1 font-medium">Mission</p>
                                    <p className="text-muted-foreground">
                                        {organization.mission}
                                    </p>
                                </div>
                            )}
                            {organization.vision && (
                                <div>
                                    <p className="mb-1 font-medium">Vision</p>
                                    <p className="text-muted-foreground">
                                        {organization.vision}
                                    </p>
                                </div>
                            )}
                            {organization.achievements && (
                                <div>
                                    <p className="mb-1 font-medium">
                                        Previous projects and achievements
                                    </p>
                                    <p className="text-muted-foreground">
                                        {organization.achievements}
                                    </p>
                                </div>
                            )}
                        </PanelBody>
                    </Panel>

                    {organization.listings.length > 0 && (
                        <Panel>
                            <PanelHeader title="Seeking funding" />
                            <PanelBody className="grid gap-3">
                                {organization.listings.map((listing) => (
                                    <ListingCard
                                        key={listing.id}
                                        listing={listing}
                                    />
                                ))}
                            </PanelBody>
                        </Panel>
                    )}

                    <PublicDocuments documents={organization.documents} />
                </div>

                <div className="grid content-start gap-4">
                    <ConnectionPanel
                        slug={organization.slug}
                        name={organization.name}
                        connection={connection}
                        canInvite={canInvite}
                    />
                    <Panel>
                        <PanelHeader title="Details" />
                        <PanelBody>
                            <dl className="grid gap-3 text-sm">
                                <Detail
                                    label="Legal name"
                                    value={organization.legalName}
                                />
                                <Detail
                                    label="Location"
                                    value={[
                                        organization.location,
                                        organization.countryCode,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                />
                                <Detail
                                    label="Operating areas"
                                    value={organization.operatingAreas.join(
                                        ', ',
                                    )}
                                />
                                <Detail
                                    label="Sectors"
                                    value={organization.sectors.join(', ')}
                                />
                                <Detail
                                    label="Registration"
                                    value={[
                                        organization.registrationNumber,
                                        organization.registrationAuthority,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')}
                                />
                                {organization.website && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Website
                                        </dt>
                                        <dd>
                                            <a
                                                href={organization.website}
                                                target="_blank"
                                                rel="noopener noreferrer nofollow"
                                                className="break-all text-primary hover:underline"
                                            >
                                                {organization.website}
                                            </a>
                                        </dd>
                                    </div>
                                )}
                                <Detail
                                    label="Email"
                                    value={organization.contactEmail}
                                />
                                <Detail
                                    label="Phone"
                                    value={organization.contactPhone}
                                />
                            </dl>
                        </PanelBody>
                    </Panel>
                </div>
            </div>
        </>
    );
}

/**
 * Inviting to partner connects nothing until they accept, and shares nothing
 * until they choose to, grant by grant.
 */
function ConnectionPanel({
    slug,
    name,
    connection,
    canInvite,
}: {
    slug: string;
    name: string;
    connection: 'connected' | 'invited' | null;
    canInvite: boolean;
}) {
    if (!connection && !canInvite) {
        return null;
    }

    return (
        <Panel>
            <PanelHeader title="Partnership" />
            <PanelBody className="grid gap-3 text-sm">
                {connection === 'connected' && (
                    <StatusBadge tone="success" className="w-fit">
                        Connected
                    </StatusBadge>
                )}
                {connection === 'invited' && (
                    <>
                        <StatusBadge tone="info" className="w-fit">
                            Invitation sent
                        </StatusBadge>
                        <p className="text-muted-foreground">
                            Waiting for {name} to accept.
                        </p>
                    </>
                )}
                {!connection && canInvite && (
                    <Form
                        {...invite.form({ slug })}
                        options={{ preserveScroll: true }}
                        className="grid gap-3"
                    >
                        {({ processing }) => (
                            <>
                                <p className="text-muted-foreground">
                                    Invite {name} to partner. Their
                                    administrators accept or decline; nothing is
                                    shared until they choose to.
                                </p>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="w-fit"
                                >
                                    Invite to partner
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </PanelBody>
        </Panel>
    );
}
