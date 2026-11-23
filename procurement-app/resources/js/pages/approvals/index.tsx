import { Head, Link } from '@inertiajs/react';
import { Scale } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { documentUrl } from '@/lib/links';
import { formatDateTime } from '@/lib/procurement';

type Props = {
    items: {
        type: string;
        typeLabel: string;
        id: string;
        number: string;
        title: string;
        amount: string | null;
        submittedAt: string | null;
        step: string;
    }[];
};

export default function Approvals({ items }: Props) {
    return (
        <>
            <Head title="Approvals" />
            <PageHeader title="Waiting for your decision" />
            <Panel>
                {items.length === 0 ? (
                    <EmptyState icon={Scale} title="Nothing to decide">
                        Plans, requisitions, sourcing, awards and orders that
                        wait for you appear here.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Document</TableHead>
                                <TableHead>About</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                                <TableHead>Step</TableHead>
                                <TableHead>Waiting since</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.map((row) => (
                                <TableRow
                                    key={`${row.type}-${row.id}-${row.step}`}
                                >
                                    <TableCell>
                                        <Link
                                            href={documentUrl(row.type, row.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {row.typeLabel} {row.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{row.title}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.amount ?? '—'}
                                    </TableCell>
                                    <TableCell>{row.step}</TableCell>
                                    <TableCell>
                                        {formatDateTime(row.submittedAt)}
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
