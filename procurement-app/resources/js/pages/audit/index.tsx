import { Head, router } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { SelectField } from '@/components/fields';
import { formatDateTime } from '@/lib/procurement';
import { index } from '@/routes/audit';

type Props = {
    type: string;
    events: {
        id: string;
        action: string;
        subjectType: string;
        subject: string | null;
        user: string | null;
        details: Record<string, unknown> | null;
        at: string | null;
    }[];
};

const TYPES = [
    { value: 'requisition', label: 'Requisitions' },
    { value: 'plan', label: 'Plans' },
    { value: 'sourcing', label: 'Sourcing events' },
    { value: 'quotation', label: 'Quotations' },
    { value: 'award', label: 'Awards' },
    { value: 'order', label: 'Purchase orders' },
    { value: 'receipt', label: 'Receipts' },
    { value: 'invoice', label: 'Supplier invoices' },
    { value: 'supplier', label: 'Suppliers' },
    { value: 'approval_rule', label: 'Approval rules' },
    { value: 'settings', label: 'Settings' },
];

function details(value: Record<string, unknown> | null): string {
    if (!value) {
        return '';
    }

    return Object.entries(value)
        .map(
            ([k, v]) =>
                `${k.replace(/_/g, ' ')}: ${
                    typeof v === 'string' ? v : JSON.stringify(v)
                }`,
        )
        .join(' · ');
}

export default function Audit({ type, events }: Props) {
    return (
        <>
            <Head title="Audit trail" />
            <PageHeader title="Audit trail" />
            <Panel>
                <PanelToolbar className="flex items-end gap-4">
                    <SelectField
                        label="Record"
                        value={type}
                        onChange={(value) =>
                            router.get(
                                index().url,
                                { type: value || undefined },
                                { preserveState: true, replace: true },
                            )
                        }
                        options={TYPES}
                        empty="Everything"
                        className="w-56"
                    />
                    <p className="text-xs text-muted-foreground">
                        Entries are never changed or removed. Latest 300 shown.
                    </p>
                </PanelToolbar>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>When</TableHead>
                            <TableHead>Who</TableHead>
                            <TableHead>What</TableHead>
                            <TableHead>Record</TableHead>
                            <TableHead>Details</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {events.map((e) => (
                            <TableRow key={e.id}>
                                <TableCell className="whitespace-nowrap">
                                    {formatDateTime(e.at)}
                                </TableCell>
                                <TableCell>{e.user ?? 'System'}</TableCell>
                                <TableCell className="font-mono text-xs">
                                    {e.action}
                                </TableCell>
                                <TableCell>{e.subject ?? '—'}</TableCell>
                                <TableCell className="max-w-md text-xs whitespace-normal text-muted-foreground">
                                    {details(e.details)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
