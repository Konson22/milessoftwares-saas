import { Head, Link, router } from '@inertiajs/react';
import { FileSearch } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
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
import { SelectField, Status } from '@/components/fields';
import { formatDateTime, status as statusLabel } from '@/lib/procurement';
import { index, show } from '@/routes/sourcing';

type Props = {
    status: string;
    events: {
        id: string;
        number: string;
        title: string;
        method: string;
        status: string;
        requisition: string | null;
        deadline: string | null;
        quotations: number;
    }[];
    statuses: string[];
};

export default function Sourcing({ status, events, statuses }: Props) {
    return (
        <>
            <Head title="Sourcing" />
            <PageHeader title="Sourcing" />
            <Panel>
                <PanelToolbar className="flex items-end gap-4">
                    <SelectField
                        label="Status"
                        value={status}
                        onChange={(value) =>
                            router.get(
                                index().url,
                                { status: value || undefined },
                                { preserveState: true, replace: true },
                            )
                        }
                        options={statuses.map((value) => ({
                            value,
                            label: statusLabel(value).label,
                        }))}
                        empty="Any status"
                        className="w-48"
                    />
                </PanelToolbar>
                {events.length === 0 ? (
                    <EmptyState icon={FileSearch} title="No sourcing yet">
                        Sourcing starts from an approved requisition: open it
                        and choose a method.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Number</TableHead>
                                <TableHead>Title</TableHead>
                                <TableHead>Method</TableHead>
                                <TableHead>Requisition</TableHead>
                                <TableHead>Quotations due</TableHead>
                                <TableHead className="text-right">
                                    Received
                                </TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {events.map((e) => (
                                <TableRow key={e.id}>
                                    <TableCell className="whitespace-nowrap">
                                        {e.number}
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={show(e.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {e.title}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{e.method}</TableCell>
                                    <TableCell>
                                        {e.requisition ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {formatDateTime(e.deadline)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {e.quotations}
                                    </TableCell>
                                    <TableCell>
                                        <Status value={e.status} />
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
