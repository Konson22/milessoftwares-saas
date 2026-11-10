import { Form, Head, Link } from '@inertiajs/react';
import { HandHeart } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { InterestBadge } from '@/components/marketplace';
import type { InterestData } from '@/components/marketplace';
import { formatDate } from '@/lib/status';
import { invite } from '@/routes/marketplace/interests';
import { show as showOrganization } from '@/routes/marketplace/organizations';
import { show as showListing } from '@/routes/marketplace/projects';

type Interest = InterestData & {
    organization: { slug: string; name: string };
    partnership: 'connected' | 'invited' | null;
    canInvite: boolean;
};

/** The interest this organization expressed in listings, and the answers. */
export default function MarketplaceInterests({
    interests,
}: {
    interests: Interest[];
}) {
    return (
        <>
            <Head title="Interests" />
            <PageHeader
                title="Interests"
                description="Projects you told an organization you are interested in. Interest is not a commitment: once they accept, you can invite them to partner."
            />

            <Panel>
                {interests.length === 0 ? (
                    <EmptyState icon={HandHeart} title="No interests yet">
                        Open a project in the marketplace to express interest.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Project</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Their reply</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {interests.map((interest) => (
                                <TableRow key={interest.id}>
                                    <TableCell>
                                        <Link
                                            href={showListing({
                                                listing: interest.listing.id,
                                            })}
                                            className="font-medium underline-offset-2 hover:underline"
                                        >
                                            {interest.listing.title}
                                        </Link>
                                        <Link
                                            href={showOrganization({
                                                slug: interest.organization
                                                    .slug,
                                            })}
                                            className="block text-xs text-muted-foreground hover:underline"
                                        >
                                            {interest.organization.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <InterestBadge {...interest} />
                                        <span className="block text-xs text-muted-foreground">
                                            Sent{' '}
                                            {formatDate(interest.submittedAt)}
                                        </span>
                                    </TableCell>
                                    <TableCell className="max-w-80 text-sm whitespace-pre-line">
                                        {interest.response ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {interest.partnership ===
                                            'connected' && (
                                            <StatusBadge tone="success">
                                                Connected
                                            </StatusBadge>
                                        )}
                                        {interest.partnership === 'invited' && (
                                            <StatusBadge tone="info">
                                                Invitation sent
                                            </StatusBadge>
                                        )}
                                        {interest.canInvite && (
                                            <Form
                                                {...invite.form({
                                                    interest: interest.id,
                                                })}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        size="sm"
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        Invite to partner
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
