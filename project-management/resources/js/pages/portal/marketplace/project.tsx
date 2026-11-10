import { Form, Head, Link } from '@inertiajs/react';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid } from '@/components/field';
import { FundingBar, InterestBadge, TextArea } from '@/components/marketplace';
import type { InterestData, ListingCardData } from '@/components/marketplace';
import { PublicDocuments } from '@/components/public-documents';
import { formatDate } from '@/lib/status';
import { show as showOrganization } from '@/routes/marketplace/organizations';
import { interest as expressInterest } from '@/routes/marketplace/projects';

/** One project seeking funding, as its organization published it. */
export default function MarketplaceProject({
    listing,
    interest,
    canExpress,
    contact,
}: {
    listing: ListingCardData & {
        objectives: string | null;
        outcomes: string | null;
        documents: { id: string; title: string }[];
    };
    interest: InterestData | null;
    canExpress: boolean;
    contact: { name: string; email: string };
}) {
    return (
        <>
            <Head title={listing.title} />
            <PageHeader
                title={listing.title}
                description={
                    <>
                        By{' '}
                        <Link
                            href={showOrganization({
                                slug: listing.organization.slug,
                            })}
                            className="font-medium text-primary hover:underline"
                        >
                            {listing.organization.name}
                        </Link>
                        {listing.location && ` · ${listing.location}`}
                        {listing.countryCode && ` · ${listing.countryCode}`}
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="grid content-start gap-4 lg:col-span-2">
                    <Panel>
                        <PanelHeader title="About the project" />
                        <PanelBody className="grid gap-4 text-sm whitespace-pre-line">
                            {listing.summary && <p>{listing.summary}</p>}
                            {listing.objectives && (
                                <div>
                                    <p className="mb-1 font-medium">
                                        Objectives
                                    </p>
                                    <p className="text-muted-foreground">
                                        {listing.objectives}
                                    </p>
                                </div>
                            )}
                            {listing.outcomes && (
                                <div>
                                    <p className="mb-1 font-medium">
                                        Expected outcomes
                                    </p>
                                    <p className="text-muted-foreground">
                                        {listing.outcomes}
                                    </p>
                                </div>
                            )}
                        </PanelBody>
                    </Panel>
                    <PublicDocuments documents={listing.documents} />
                </div>

                <div className="grid content-start gap-4">
                    <Panel>
                        <PanelHeader title="Funding" />
                        <PanelBody className="grid gap-4 text-sm">
                            <StatusBadge tone="info" className="w-fit">
                                {listing.fundingStatus}
                            </StatusBadge>
                            <FundingBar {...listing} />
                            <dl className="grid gap-2">
                                {listing.sector && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Sector
                                        </dt>
                                        <dd>{listing.sector}</dd>
                                    </div>
                                )}
                                {(listing.startOn || listing.endOn) && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Duration
                                        </dt>
                                        <dd>
                                            {listing.startOn
                                                ? formatDate(listing.startOn)
                                                : '—'}{' '}
                                            to{' '}
                                            {listing.endOn
                                                ? formatDate(listing.endOn)
                                                : '—'}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                            <p className="text-xs text-muted-foreground">
                                Amounts are as the organization states them. A
                                funding target is not an approved budget.
                            </p>
                        </PanelBody>
                    </Panel>

                    <InterestPanel
                        listingId={listing.id}
                        organization={listing.organization.name}
                        interest={interest}
                        canExpress={canExpress}
                        contact={contact}
                    />
                </div>
            </div>
        </>
    );
}

/**
 * Interest is a message, never a commitment: the organization answers, and
 * may then be invited to partner.
 */
function InterestPanel({
    listingId,
    organization,
    interest,
    canExpress,
    contact,
}: {
    listingId: string;
    organization: string;
    interest: InterestData | null;
    canExpress: boolean;
    contact: { name: string; email: string };
}) {
    if (!interest && !canExpress) {
        return null;
    }

    return (
        <Panel>
            <PanelHeader
                title="Your interest"
                description={`Tell ${organization} you would like to talk. It is not a commitment to fund.`}
            />
            <PanelBody className="grid gap-4 text-sm">
                {interest && (
                    <div className="grid gap-1">
                        <InterestBadge {...interest} />
                        <p className="text-xs text-muted-foreground">
                            Sent {formatDate(interest.submittedAt)}
                        </p>
                        {interest.response && (
                            <p className="whitespace-pre-line">
                                {interest.response}
                            </p>
                        )}
                    </div>
                )}
                {canExpress && (
                    <Form
                        {...expressInterest.form({ listing: listingId })}
                        options={{ preserveScroll: true }}
                        className="grid gap-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="contact_name"
                                    label="Contact person"
                                    error={errors.contact_name}
                                >
                                    <Input
                                        id="contact_name"
                                        name="contact_name"
                                        defaultValue={contact.name}
                                        required
                                        {...invalid(
                                            'contact_name',
                                            errors.contact_name,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="contact_email"
                                    label="Contact email"
                                    error={errors.contact_email}
                                >
                                    <Input
                                        id="contact_email"
                                        name="contact_email"
                                        type="email"
                                        defaultValue={contact.email}
                                        required
                                        {...invalid(
                                            'contact_email',
                                            errors.contact_email,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="message"
                                    label="Message"
                                    error={errors.message}
                                >
                                    <TextArea
                                        id="message"
                                        name="message"
                                        required
                                        maxLength={5000}
                                        placeholder="What interests you, and what you would like to discuss."
                                        {...invalid('message', errors.message)}
                                    />
                                </Field>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="w-fit"
                                >
                                    Express interest
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </PanelBody>
        </Panel>
    );
}
