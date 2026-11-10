import { Head, router } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Select } from '@/components/field';
import { formatDateTime } from '@/lib/status';
import { unit as saveUnit } from '@/routes/admin/members';
import type { Option } from '@/types';

type Member = {
    id: string;
    name: string;
    email: string;
    isOwner: boolean;
    funder: string | null;
    status: string;
    unitId: string | null;
    roles: string[];
    lastLoginAt: string | null;
};

const roleName = (key: string) =>
    key.replaceAll('_', ' ').replace(/^./, (first) => first.toUpperCase());

/**
 * Administration → Members: who has opened Projects here, the roles the
 * platform gives them, and the unit each belongs to.
 */
export default function Members({
    members,
    units,
    platformMembersUrl,
}: {
    members: Member[];
    units: Option[];
    platformMembersUrl: string;
}) {
    return (
        <>
            <Head title="Members" />
            <PageHeader
                title="Members"
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <a href={platformMembersUrl}>Invite and set roles</a>
                    </Button>
                }
            />

            <Panel>
                <PanelHeader
                    title={`Members (${members.length})`}
                    description="Everyone who has opened Projects here. Invitations, roles, suspending and removing are on the workspace's members page."
                />
                {members.length === 0 ? (
                    <EmptyState icon={Users} title="Nobody yet" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Unit</TableHead>
                                <TableHead>Roles</TableHead>
                                <TableHead>Last sign-in</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell>
                                        <span className="font-medium">
                                            {member.name}
                                        </span>
                                        {member.status !== 'active' && (
                                            <StatusBadge
                                                tone="warning"
                                                className="ml-2"
                                            >
                                                No access
                                            </StatusBadge>
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            {member.email}
                                        </p>
                                    </TableCell>
                                    <TableCell>
                                        <Select
                                            aria-label={`Unit of ${member.name}`}
                                            placeholder="No unit"
                                            options={units}
                                            value={member.unitId ?? ''}
                                            onChange={(event) =>
                                                router.put(
                                                    saveUnit(member.id).url,
                                                    {
                                                        org_unit_id:
                                                            event.target
                                                                .value || null,
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {member.isOwner
                                            ? 'Owner'
                                            : member.funder
                                              ? `Contact of ${member.funder}`
                                              : member.roles
                                                    .map(roleName)
                                                    .join(', ') || '—'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {member.lastLoginAt
                                            ? formatDateTime(member.lastLoginAt)
                                            : 'Never'}
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
