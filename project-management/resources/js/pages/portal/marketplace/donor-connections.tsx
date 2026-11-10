import { Form, Head, Link } from '@inertiajs/react';
import { Handshake } from 'lucide-react';
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
import { formatDate } from '@/lib/status';
import { accept, decline } from '@/routes/donor-connections';

type Connected = {
    id: string;
    donor: string;
    label: string;
    since: string | null;
    grants: number;
    /** Records a grant with this donor chosen; null without grants.manage. */
    recordGrantUrl: string | null;
};

type Invitation = {
    id: string;
    donor: string;
    label: string;
    invitedBy: string;
    expiresOn: string;
};

/**
 * Donors this organization works with, and their invitations to partner.
 * Connecting shares nothing by itself: each grant chooses what its funder sees.
 */
export default function DonorConnections({
    connected,
    invitations,
}: {
    connected: Connected[];
    invitations: Invitation[];
}) {
    return (
        <>
            <Head title="Connected donors" />
            <PageHeader
                title="Connected donors"
                description="Donors you partner with. They see only what you share, grant by grant; your projects, books and team stay your own."
            />

            <div className="grid gap-4">
                {invitations.length > 0 && (
                    <Panel>
                        <PanelHeader title="Invitations" />
                        <PanelBody className="grid gap-3 text-sm">
                            {invitations.map((invitation) => (
                                <div
                                    key={invitation.id}
                                    className="flex flex-wrap items-center justify-between gap-3 border-b pb-3 last:border-0 last:pb-0"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {invitation.donor}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            As a{' '}
                                            {invitation.label.toLowerCase()} ·
                                            invited by {invitation.invitedBy} ·
                                            until{' '}
                                            {formatDate(invitation.expiresOn)}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        <Form
                                            {...accept.form({
                                                partnership: invitation.id,
                                            })}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    size="sm"
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Accept
                                                </Button>
                                            )}
                                        </Form>
                                        <Form
                                            {...decline.form({
                                                partnership: invitation.id,
                                            })}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Decline
                                                </Button>
                                            )}
                                        </Form>
                                    </div>
                                </div>
                            ))}
                        </PanelBody>
                    </Panel>
                )}

                <Panel>
                    {connected.length === 0 ? (
                        <EmptyState
                            icon={Handshake}
                            title="No connected donors"
                        >
                            A donor can invite you from your public profile, or
                            by email.
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Donor</TableHead>
                                    <TableHead>You are their</TableHead>
                                    <TableHead>Since</TableHead>
                                    <TableHead className="text-right">
                                        Grants
                                    </TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {connected.map((partnership) => (
                                    <TableRow key={partnership.id}>
                                        <TableCell className="font-medium">
                                            {partnership.donor}
                                        </TableCell>
                                        <TableCell>
                                            {partnership.label}
                                        </TableCell>
                                        <TableCell>
                                            {partnership.since
                                                ? formatDate(partnership.since)
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {partnership.grants}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {partnership.recordGrantUrl && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <Link
                                                        href={
                                                            partnership.recordGrantUrl
                                                        }
                                                    >
                                                        Record a grant
                                                    </Link>
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>
            </div>
        </>
    );
}
