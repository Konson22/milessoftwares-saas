import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList, Plus } from 'lucide-react';
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
import { Check, SelectField, Status } from '@/components/fields';
import { formatDate, money, status as statusLabel } from '@/lib/procurement';
import { create, index, show } from '@/routes/requisitions';

type Props = {
    status: string;
    mine: boolean;
    requisitions: {
        id: string;
        number: string | null;
        title: string;
        status: string;
        requester: string;
        department: string | null;
        total: string;
        currency: string;
        neededBy: string | null;
        date: string | null;
    }[];
    statuses: string[];
    canCreate: boolean;
};

export default function Requisitions({
    status,
    mine,
    requisitions,
    statuses,
    canCreate,
}: Props) {
    const filter = (query: { status: string; mine: boolean }) =>
        router.get(
            index().url,
            {
                status: query.status || undefined,
                mine: query.mine ? 1 : undefined,
            },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Requisitions" />
            <PageHeader
                title="Requisitions"
                actions={
                    canCreate && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus /> New requisition
                            </Link>
                        </Button>
                    )
                }
            />
            <Panel>
                <PanelToolbar className="flex flex-wrap items-end gap-4">
                    <SelectField
                        label="Status"
                        value={status}
                        onChange={(value) => filter({ status: value, mine })}
                        options={statuses.map((value) => ({
                            value,
                            label: statusLabel(value).label,
                        }))}
                        empty="Any status"
                        className="w-48"
                    />
                    <Check
                        label="Only mine"
                        checked={mine}
                        onChange={(checked) =>
                            filter({ status, mine: checked })
                        }
                    />
                </PanelToolbar>
                {requisitions.length === 0 ? (
                    <EmptyState icon={ClipboardList} title="No requisitions">
                        A requisition asks for something to be bought. Once
                        approved, procurement sources it.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Number</TableHead>
                                <TableHead>Title</TableHead>
                                <TableHead>Requested by</TableHead>
                                <TableHead>Needed by</TableHead>
                                <TableHead className="text-right">
                                    Estimate
                                </TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {requisitions.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell className="whitespace-nowrap">
                                        {row.number ?? 'Draft'}
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={show(row.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {row.title}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {row.requester}
                                        {row.department && (
                                            <span className="block text-xs text-muted-foreground">
                                                {row.department}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {formatDate(row.neededBy)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(row.total, row.currency)}
                                    </TableCell>
                                    <TableCell>
                                        <Status value={row.status} />
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
