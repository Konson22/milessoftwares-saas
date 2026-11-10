import { Form, Head, Link } from '@inertiajs/react';
import { Megaphone } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, Select } from '@/components/field';
import { PublicationBadge } from '@/components/marketplace';
import { formatMoney } from '@/lib/money';
import { edit, store } from '@/routes/funding-listings';
import { edit as editProfile } from '@/routes/public-profile';
import type { Option } from '@/types';

type Listing = {
    id: string;
    title: string;
    project: string;
    publication: string;
    funding: string;
    currency: string;
    targetMinor: number;
    remainingMinor: number;
};

/** Projects shown to donors as seeking funding. Each is a public copy. */
export default function FundingListings({
    listings,
    projects,
    profilePublished,
}: {
    listings: Listing[];
    projects: Option[];
    profilePublished: boolean;
}) {
    return (
        <>
            <Head title="Funding listings" />
            <PageHeader
                title="Funding listings"
                description="Projects you show donors as seeking funding. A project stays private until you publish its listing, and only what you type here is shown."
            />

            <div className="grid gap-4">
                {!profilePublished && (
                    <Panel>
                        <PanelBody className="text-sm text-muted-foreground">
                            Listings show beside your public profile.{' '}
                            <Link
                                href={editProfile()}
                                className="font-medium text-primary hover:underline"
                            >
                                Publish your profile
                            </Link>{' '}
                            before publishing a listing.
                        </PanelBody>
                    </Panel>
                )}

                <Panel>
                    {listings.length === 0 ? (
                        <EmptyState icon={Megaphone} title="No listings yet" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Listing</TableHead>
                                    <TableHead>Published</TableHead>
                                    <TableHead>Funding</TableHead>
                                    <TableHead className="text-right">
                                        Still needed
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {listings.map((listing) => (
                                    <TableRow key={listing.id}>
                                        <TableCell>
                                            <Link
                                                href={edit({
                                                    listing: listing.id,
                                                })}
                                                className="font-medium underline-offset-2 hover:underline"
                                            >
                                                {listing.title}
                                            </Link>
                                            <span className="block text-xs text-muted-foreground">
                                                {listing.project}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <PublicationBadge
                                                status={listing.publication}
                                            />
                                        </TableCell>
                                        <TableCell>{listing.funding}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMoney(
                                                listing.remainingMinor,
                                                listing.currency,
                                            )}
                                            <span className="block text-xs text-muted-foreground">
                                                of{' '}
                                                {formatMoney(
                                                    listing.targetMinor,
                                                    listing.currency,
                                                )}
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                <Panel>
                    <PanelHeader
                        title="List a project"
                        description="Starts a draft from the project's name, description and dates. Nothing is public until you publish it."
                    />
                    <PanelBody>
                        {projects.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Every open project already has a listing.
                            </p>
                        ) : (
                            <Form
                                {...store.form()}
                                className="flex flex-wrap items-end gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="project_id"
                                            label="Project"
                                            error={errors.project_id}
                                            className="min-w-64"
                                        >
                                            <Select
                                                id="project_id"
                                                name="project_id"
                                                options={projects}
                                                required
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Start a listing
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            </div>
        </>
    );
}
