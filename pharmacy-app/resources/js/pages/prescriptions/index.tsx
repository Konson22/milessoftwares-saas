import { Head, Link, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { statusTone } from '@/lib/status';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useCanHere } from '@/lib/access';
import { cn } from '@miles/ui/lib/utils';
import { create, index, show } from '@/routes/prescriptions';

type Prescription = {
    id: string;
    number: string;
    patientName: string;
    prescriberName: string;
    issuedOn: string;
    status: string;
    statusLabel: string;
    items: number;
};

type Props = {
    prescriptions: Prescription[];
    status: string | null;
    statuses: { value: string; label: string }[];
};

export default function Prescriptions({
    prescriptions,
    status,
    statuses,
}: Props) {
    const { branch } = usePage().props;
    const canHere = useCanHere();

    return (
        <>
            <Head title="Prescriptions" />
            <PageHeader
                title="Prescriptions"
                description={branch?.name}
                actions={
                    canHere('pharmacy.prescriptions.record') ? (
                        <Button asChild>
                            <Link href={create()}>Record a prescription</Link>
                        </Button>
                    ) : undefined
                }
            />

            <Panel>
                <PanelToolbar>
                    <nav
                        aria-label="Filter by status"
                        className="flex flex-wrap gap-2"
                    >
                        <Button
                            size="sm"
                            variant={status === null ? 'default' : 'outline'}
                            asChild
                        >
                            <Link href={index()}>All</Link>
                        </Button>
                        {statuses.map((option) => (
                            <Button
                                key={option.value}
                                size="sm"
                                variant={
                                    status === option.value
                                        ? 'default'
                                        : 'outline'
                                }
                                asChild
                            >
                                <Link
                                    href={index({
                                        query: { status: option.value },
                                    })}
                                >
                                    {option.label}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </PanelToolbar>

                {prescriptions.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title="No prescriptions"
                        action={
                            canHere('pharmacy.prescriptions.record') ? (
                                <Button asChild>
                                    <Link href={create()}>
                                        Record a prescription
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Number</TableHead>
                                <TableHead>Patient</TableHead>
                                <TableHead>Prescriber</TableHead>
                                <TableHead>Issued</TableHead>
                                <TableHead>Items</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {prescriptions.map((prescription) => (
                                <TableRow key={prescription.id}>
                                    <TableCell>
                                        <Link
                                            href={show(prescription.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {prescription.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {prescription.patientName}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {prescription.prescriberName}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {prescription.issuedOn}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {prescription.items}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={statusTone(
                                                prescription.status,
                                            )}
                                            className={cn(
                                                prescription.status ===
                                                    'recorded' &&
                                                    'border-blue-200 bg-blue-50 text-blue-700',
                                            )}
                                        >
                                            {prescription.statusLabel}
                                        </StatusBadge>
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
