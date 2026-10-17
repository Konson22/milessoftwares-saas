import { Head, Link, usePage } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { index } from '@/routes/controlled';

type Entry = {
    id: string;
    at: string;
    type: string;
    quantity: number;
    balance: number;
    batchNumber: string;
    by: string;
    document: string | null;
    supplier: string | null;
    prescription: string | null;
    patient: string | null;
    prescriber: string | null;
    reason: string | null;
};

type Props = {
    medicines: { id: string; label: string }[];
    selected: string | null;
    entries: Entry[];
};

const TYPES: Record<string, string> = {
    opening: 'Opening stock',
    receipt: 'Received',
    sale: 'Dispensed',
    sale_void: 'Returned (sale voided)',
    sale_return: 'Returned by the customer',
    disposal: 'Disposed of',
    supplier_return: 'Returned to supplier',
    adjustment: 'Adjusted',
};

/**
 * The controlled drugs register: every movement of one controlled medicine
 * in this branch, newest first, with the balance after it.
 */
export default function ControlledRegister({
    medicines,
    selected,
    entries,
}: Props) {
    const { branch } = usePage().props;

    return (
        <>
            <Head title="Controlled drugs register" />
            <PageHeader
                title="Controlled drugs register"
                description={branch?.name}
            />

            {medicines.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={ShieldAlert}
                        title="No medicine is marked as controlled"
                    />
                </Panel>
            ) : (
                <Panel>
                    <PanelToolbar>
                        <nav
                            aria-label="Controlled medicines"
                            className="flex flex-wrap gap-2"
                        >
                            {medicines.map((medicine) => (
                                <Button
                                    key={medicine.id}
                                    size="sm"
                                    variant={
                                        medicine.id === selected
                                            ? 'default'
                                            : 'outline'
                                    }
                                    asChild
                                >
                                    <Link
                                        href={index({
                                            query: { medicine: medicine.id },
                                        })}
                                    >
                                        {medicine.label}
                                    </Link>
                                </Button>
                            ))}
                        </nav>
                    </PanelToolbar>

                    {entries.length === 0 ? (
                        <EmptyState
                            icon={ShieldAlert}
                            title="No movements in this branch"
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    <TableHead>What</TableHead>
                                    <TableHead className="text-right">
                                        In / out
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Balance
                                    </TableHead>
                                    <TableHead>Batch</TableHead>
                                    <TableHead>Document</TableHead>
                                    <TableHead>For / from</TableHead>
                                    <TableHead>By</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.map((entry) => (
                                    <TableRow key={entry.id}>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {new Date(
                                                entry.at,
                                            ).toLocaleString()}
                                        </TableCell>
                                        <TableCell>
                                            {TYPES[entry.type] ?? entry.type}
                                            {entry.reason && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {entry.reason}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={`text-right tabular-nums ${entry.quantity < 0 ? 'text-destructive' : ''}`}
                                        >
                                            {entry.quantity > 0 ? '+' : ''}
                                            {entry.quantity}
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {entry.balance}
                                        </TableCell>
                                        <TableCell>
                                            {entry.batchNumber}
                                        </TableCell>
                                        <TableCell>
                                            {entry.document}
                                            {entry.prescription && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {entry.prescription}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {entry.patient ?? entry.supplier}
                                            {entry.prescriber && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {entry.prescriber}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>{entry.by}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>
            )}
        </>
    );
}
