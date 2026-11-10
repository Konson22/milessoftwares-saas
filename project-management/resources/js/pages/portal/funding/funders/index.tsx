import { Head, Link } from '@inertiajs/react';
import { Landmark, Plus } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
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
import { create, edit } from '@/routes/funders';

type Funder = {
    id: string;
    name: string;
    kind: string;
    countryCode: string | null;
    contactName: string | null;
    contactEmail: string | null;
    active: boolean;
    grants: number;
    activeGrants: number;
};

/**
 * The organization's funders: institutional donors, foundations,
 * governments... and how many grants each has given.
 */
export default function Funders({
    funders,
    canManage,
}: {
    funders: Funder[];
    canManage: boolean;
}) {
    const add = canManage && (
        <Button size="sm" asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Add a funder
            </Link>
        </Button>
    );

    return (
        <>
            <Head title="Funders" />
            <PageHeader
                title="Funders"
                description="Who funds your work. Grants are recorded against them."
                actions={add}
            />

            <Panel>
                {funders.length === 0 ? (
                    <EmptyState
                        icon={Landmark}
                        title="No funders yet"
                        action={add}
                    >
                        Add the donors, foundations and agencies you work with.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Kind</TableHead>
                                <TableHead>Contact</TableHead>
                                <TableHead className="text-right">
                                    Grants
                                </TableHead>
                                {canManage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {funders.map((funder) => (
                                <TableRow key={funder.id}>
                                    <TableCell>
                                        <span className="font-medium">
                                            {funder.name}
                                        </span>
                                        {funder.countryCode && (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                {funder.countryCode}
                                            </span>
                                        )}
                                        {!funder.active && (
                                            <StatusBadge className="ml-2">
                                                Inactive
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {funder.kind}
                                    </TableCell>
                                    <TableCell>
                                        {funder.contactName ?? '—'}
                                        {funder.contactEmail && (
                                            <p className="text-xs text-muted-foreground">
                                                {funder.contactEmail}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {funder.activeGrants} active
                                        <span className="text-muted-foreground">
                                            {' '}
                                            / {funder.grants}
                                        </span>
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={edit({
                                                        funder: funder.id,
                                                    })}
                                                >
                                                    Edit
                                                </Link>
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
