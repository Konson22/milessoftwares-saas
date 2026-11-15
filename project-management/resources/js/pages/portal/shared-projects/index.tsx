import { Head, Link, router } from '@inertiajs/react';
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
import type { InvitationRow, ParticipantRow } from '@/components/collaboration';
import { ParticipationStatus } from '@/components/collaboration';
import { formatDate } from '@/lib/status';
import { accept, decline } from '@/routes/shared-projects/invitations';

type Props = {
    invitations: (InvitationRow & { project: string })[];
    projects: (ParticipantRow & {
        project: string;
        code: string;
        owner: string;
        url: string;
    })[];
    canManage: boolean;
};

/**
 * Projects of other organizations this one takes part in, and invitations
 * waiting for an answer. Each member sees the projects they work on;
 * those who manage the organization's collaboration see them all.
 */
export default function SharedProjects({
    invitations,
    projects,
    canManage,
}: Props) {
    return (
        <>
            <Head title="Shared projects" />
            <PageHeader title="Shared projects" />

            <div className="grid gap-4">
                {invitations.length > 0 && (
                    <Panel>
                        <PanelHeader
                            title="Invitations waiting"
                            description="Accepting gives the project's owner nothing of yours. You see what they give you, in this workspace."
                        />
                        <ul className="divide-y">
                            {invitations.map((invitation) => (
                                <li key={invitation.id}>
                                    <PanelBody className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-center">
                                        <div className="text-sm">
                                            <p>
                                                <span className="font-medium">
                                                    {invitation.from}
                                                </span>{' '}
                                                invites you to{' '}
                                                <span className="font-medium">
                                                    {invitation.project}
                                                </span>{' '}
                                                as{' '}
                                                {invitation.relationshipLabel.toLowerCase()}
                                                .
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                You would be able to:{' '}
                                                {invitation.capabilities.join(
                                                    ', ',
                                                )}
                                                . Sent by {invitation.inviter},
                                                until{' '}
                                                {formatDate(
                                                    invitation.expiresAt,
                                                )}
                                                .
                                            </p>
                                            {invitation.message && (
                                                <p className="mt-1 text-xs italic">
                                                    “{invitation.message}”
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        accept.url({
                                                            invitation:
                                                                invitation.id,
                                                        }),
                                                    )
                                                }
                                            >
                                                Accept
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        decline.url({
                                                            invitation:
                                                                invitation.id,
                                                        }),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Decline
                                            </Button>
                                        </div>
                                    </PanelBody>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}

                <Panel>
                    <PanelHeader title="Projects you take part in" />
                    {projects.length === 0 ? (
                        <EmptyState icon={Handshake} title="No shared projects">
                            {canManage
                                ? 'When another organization invites yours to a project, it appears here.'
                                : 'Those who manage your organization choose who works on shared projects.'}
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Project</TableHead>
                                    <TableHead>Owner</TableHead>
                                    <TableHead>You take part as</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {projects.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <Link
                                                href={row.url}
                                                className="font-medium hover:underline"
                                            >
                                                {row.code} · {row.project}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{row.owner}</TableCell>
                                        <TableCell>
                                            {row.relationshipLabel}
                                            {row.parent && (
                                                <p className="text-xs text-muted-foreground">
                                                    under {row.parent}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <ParticipationStatus
                                                status={row.status}
                                                cutOff={row.cutOff}
                                            />
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
