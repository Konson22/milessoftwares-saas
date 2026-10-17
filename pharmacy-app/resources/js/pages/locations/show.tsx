import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { index } from '@/routes/locations';
import { show as stockShow } from '@/routes/stock';

type Props = {
    location: { id: string; name: string; active: boolean };
    contents: {
        id: string;
        itemId: string;
        medicine: string;
        batchNumber: string;
        expiresOn: string;
        status: string;
        quantity: number;
    }[];
};

/**
 * What one location holds, batch by batch: a count sheet (printable).
 */
export default function Location({ location, contents }: Props) {
    return (
        <>
            <Head title={location.name} />
            <PageHeader
                title={location.name}
                badges={
                    location.active ? undefined : (
                        <StatusBadge tone="neutral">Not in use</StatusBadge>
                    )
                }
                actions={
                    <div className="flex gap-2 print:hidden">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>← Locations</Link>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => window.print()}
                        >
                            Print count sheet
                        </Button>
                    </div>
                }
            />

            <Panel>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medicine</TableHead>
                            <TableHead>Batch</TableHead>
                            <TableHead>Expires</TableHead>
                            <TableHead className="text-right">
                                Should be here
                            </TableHead>
                            <TableHead className="hidden w-32 print:table-cell">
                                Counted
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {contents.length === 0 ? (
                            <TableEmpty colSpan={4}>Nothing here.</TableEmpty>
                        ) : (
                            contents.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <Link
                                            href={stockShow(row.itemId)}
                                            className="font-medium hover:underline"
                                        >
                                            {row.medicine}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {row.batchNumber}{' '}
                                        {row.status !== 'available' && (
                                            <StatusBadge tone="warning">
                                                {row.status}
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {row.expiresOn}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.quantity}
                                    </TableCell>
                                    <TableCell className="hidden border-b print:table-cell" />
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
