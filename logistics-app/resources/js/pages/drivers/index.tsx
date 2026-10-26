import { Head, Link } from '@inertiajs/react';
import { Plus, UserRound } from 'lucide-react';
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
import { formatDate } from '@/lib/status';
import { create, edit } from '@/routes/drivers';

type Driver = {
    id: string;
    name: string;
    phone: string | null;
    licenceNumber: string | null;
    licenceExpiresOn: string | null;
    licenceExpired: boolean;
    licenceExpiring: boolean;
    active: boolean;
    completedTrips: number;
    onTrip: string | null;
};

export default function Drivers({
    drivers,
    canManage,
}: {
    drivers: Driver[];
    canManage: boolean;
}) {
    const add = canManage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Add a driver
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Drivers" />
            <PageHeader
                title="Drivers"
                description="Who drives, and whether their licence is valid."
                actions={add}
            />

            <Panel>
                {drivers.length === 0 ? (
                    <EmptyState
                        icon={UserRound}
                        title="No drivers yet"
                        action={add}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Driver</TableHead>
                                <TableHead>Phone</TableHead>
                                <TableHead>Licence</TableHead>
                                <TableHead className="text-right">
                                    Trips completed
                                </TableHead>
                                <TableHead>Now</TableHead>
                                {canManage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {drivers.map((driver) => (
                                <TableRow key={driver.id}>
                                    <TableCell className="font-medium">
                                        {driver.name}
                                        {!driver.active && (
                                            <StatusBadge className="ml-2">
                                                Inactive
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {driver.phone ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {driver.licenceNumber ?? '—'}
                                        {driver.licenceExpiresOn && (
                                            <span className="block text-xs text-muted-foreground">
                                                Valid to{' '}
                                                {formatDate(
                                                    driver.licenceExpiresOn,
                                                )}
                                            </span>
                                        )}
                                        {driver.licenceExpired && (
                                            <StatusBadge tone="danger">
                                                Expired
                                            </StatusBadge>
                                        )}
                                        {driver.licenceExpiring && (
                                            <StatusBadge tone="warning">
                                                Renew soon
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {driver.completedTrips}
                                    </TableCell>
                                    <TableCell>
                                        {driver.onTrip ? (
                                            <StatusBadge tone="warning">
                                                On trip {driver.onTrip}
                                            </StatusBadge>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={edit(driver.id)}>
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
