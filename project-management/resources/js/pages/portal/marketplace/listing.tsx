import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import {
    CheckboxGroup,
    PublicationBadge,
    TextArea,
} from '@/components/marketplace';
import { formatMoney } from '@/lib/money';
import { index, publish, unpublish, update } from '@/routes/funding-listings';
import type { Option } from '@/types';

type Listing = {
    id: string;
    project: string;
    title: string;
    summary: string | null;
    sector: string | null;
    country_code: string | null;
    location: string | null;
    objectives: string | null;
    outcomes: string | null;
    start_on: string | null;
    end_on: string | null;
    currency: string;
    target: string;
    confirmed: string;
    remainingMinor: number;
    funding_status: string;
    publication: string;
    documents: string[];
};

const keep = { preserveScroll: true };

/**
 * One funding listing: its public text, target and confirmed funding, and
 * whether donors see it. Publishing and how funded it is are set apart.
 */
export default function FundingListing({
    listing,
    internal,
    sectors,
    fundingStatuses,
    documents,
    profilePublished,
}: {
    listing: Listing;
    internal: {
        budgetMinor: number;
        budgetCurrency: string;
        awardedMinor: number;
    };
    sectors: Option[];
    fundingStatuses: Option[];
    documents: Option[];
    profilePublished: boolean;
}) {
    const { errors } = usePage().props;
    const published = listing.publication === 'published';

    return (
        <>
            <Head title={listing.title} />
            <PageHeader
                title={listing.title}
                description={`Listing for ${listing.project}.`}
            />

            <div className="grid gap-4">
                <Panel>
                    <PanelBody className="flex flex-wrap items-center justify-between gap-4">
                        <div className="grid gap-1">
                            <div className="flex items-center gap-2">
                                <PublicationBadge
                                    status={listing.publication}
                                />
                                <span className="text-sm text-muted-foreground">
                                    {formatMoney(
                                        listing.remainingMinor,
                                        listing.currency,
                                    )}{' '}
                                    still needed
                                </span>
                            </div>
                            {!profilePublished && !published && (
                                <p className="text-sm text-muted-foreground">
                                    Publish your public profile first.
                                </p>
                            )}
                            {errors.publish && (
                                <p className="text-sm text-destructive">
                                    {errors.publish}
                                </p>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <Button variant="ghost" asChild>
                                <Link href={index()}>All listings</Link>
                            </Button>
                            <Form
                                {...(published
                                    ? unpublish.form({ listing: listing.id })
                                    : publish.form({ listing: listing.id }))}
                                options={keep}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant={
                                            published ? 'outline' : 'default'
                                        }
                                        disabled={processing}
                                    >
                                        {published ? 'Unpublish' : 'Publish'}
                                    </Button>
                                )}
                            </Form>
                        </div>
                    </PanelBody>
                </Panel>

                <Form
                    {...update.form({ listing: listing.id })}
                    options={keep}
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <Panel>
                                <PanelHeader title="The project, for donors" />
                                <PanelBody className="grid gap-4">
                                    <Field
                                        id="title"
                                        label="Title"
                                        error={errors.title}
                                    >
                                        <Input
                                            id="title"
                                            name="title"
                                            required
                                            defaultValue={listing.title}
                                            {...invalid('title', errors.title)}
                                        />
                                    </Field>
                                    <Field
                                        id="summary"
                                        label="Description"
                                        error={errors.summary}
                                        hint="Started from the project's own description. Check it holds nothing private."
                                    >
                                        <TextArea
                                            id="summary"
                                            name="summary"
                                            rows={5}
                                            defaultValue={listing.summary ?? ''}
                                        />
                                    </Field>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            id="objectives"
                                            label="Objectives"
                                            error={errors.objectives}
                                        >
                                            <TextArea
                                                id="objectives"
                                                name="objectives"
                                                defaultValue={
                                                    listing.objectives ?? ''
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id="outcomes"
                                            label="Expected outcomes"
                                            error={errors.outcomes}
                                        >
                                            <TextArea
                                                id="outcomes"
                                                name="outcomes"
                                                defaultValue={
                                                    listing.outcomes ?? ''
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <Field
                                            id="sector"
                                            label="Sector"
                                            error={errors.sector}
                                        >
                                            <Select
                                                id="sector"
                                                name="sector"
                                                placeholder="Choose…"
                                                options={sectors}
                                                defaultValue={
                                                    listing.sector ?? ''
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id="country_code"
                                            label="Country code"
                                            error={errors.country_code}
                                        >
                                            <Input
                                                id="country_code"
                                                name="country_code"
                                                maxLength={2}
                                                className="uppercase"
                                                defaultValue={
                                                    listing.country_code ?? ''
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id="location"
                                            label="Location"
                                            error={errors.location}
                                        >
                                            <Input
                                                id="location"
                                                name="location"
                                                defaultValue={
                                                    listing.location ?? ''
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id="start_on"
                                            label="Starts"
                                            error={errors.start_on}
                                        >
                                            <Input
                                                id="start_on"
                                                name="start_on"
                                                type="date"
                                                defaultValue={
                                                    listing.start_on ?? ''
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id="end_on"
                                            label="Ends"
                                            error={errors.end_on}
                                        >
                                            <Input
                                                id="end_on"
                                                name="end_on"
                                                type="date"
                                                defaultValue={
                                                    listing.end_on ?? ''
                                                }
                                            />
                                        </Field>
                                    </div>
                                </PanelBody>
                            </Panel>

                            <Panel>
                                <PanelHeader
                                    title="Funding"
                                    description="What you ask donors for, and how much is already confirmed. Interest from donors is never counted as confirmed."
                                />
                                <PanelBody className="grid gap-4">
                                    <div className="grid gap-4 sm:grid-cols-4">
                                        <Field
                                            id="currency"
                                            label="Currency"
                                            error={errors.currency}
                                        >
                                            <Input
                                                id="currency"
                                                name="currency"
                                                maxLength={3}
                                                required
                                                className="uppercase"
                                                defaultValue={listing.currency}
                                            />
                                        </Field>
                                        <Field
                                            id="target"
                                            label="Funding target"
                                            error={errors.target}
                                        >
                                            <Input
                                                id="target"
                                                name="target"
                                                inputMode="decimal"
                                                required
                                                defaultValue={listing.target}
                                                {...invalid(
                                                    'target',
                                                    errors.target,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="confirmed"
                                            label="Confirmed"
                                            error={errors.confirmed}
                                        >
                                            <Input
                                                id="confirmed"
                                                name="confirmed"
                                                inputMode="decimal"
                                                defaultValue={listing.confirmed}
                                                {...invalid(
                                                    'confirmed',
                                                    errors.confirmed,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="funding_status"
                                            label="Funding status"
                                            error={errors.funding_status}
                                        >
                                            <Select
                                                id="funding_status"
                                                name="funding_status"
                                                options={fundingStatuses}
                                                defaultValue={
                                                    listing.funding_status
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Only you see this: the project's
                                        approved budget is{' '}
                                        {formatMoney(
                                            internal.budgetMinor,
                                            internal.budgetCurrency,
                                        )}
                                        , and awarded grants allocate{' '}
                                        {formatMoney(
                                            internal.awardedMinor,
                                            internal.budgetCurrency,
                                        )}{' '}
                                        to it. The target need not match either.
                                    </p>
                                </PanelBody>
                            </Panel>

                            <Panel>
                                <PanelHeader
                                    title="Public documents"
                                    description="Shown with this listing. Confidential and archived documents are never offered."
                                />
                                <PanelBody>
                                    <CheckboxGroup
                                        name="documents"
                                        options={documents}
                                        selected={listing.documents}
                                        empty="No documents to choose from. Add them in Documents."
                                    />
                                </PanelBody>
                            </Panel>

                            <div>
                                <Button type="submit" disabled={processing}>
                                    Save listing
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
