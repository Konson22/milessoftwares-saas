import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
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
import { statusTone } from '@/lib/status';
import { create, index, show } from '@/routes/retail/transfers';

type Transfer = {
    id: string;
    number: string;
    from: string;
    to: string;
    status: 'in_transit' | 'received' | 'cancelled';
    lines: number;
    units: number;
    sentBy: string;
    sentAt: string;
};

type Props = {
    direction: 'incoming' | 'outgoing';
    transfers: Transfer[];
    canSend: boolean;
};

const TABS = [
    { value: 'incoming', label: 'Coming in' },
    { value: 'outgoing', label: 'Sent out' },
] as const;

/**
 * Stock on its way to and from this branch; what is still on the road first.
 */
export default function Transfers({ direction, transfers, canSend }: Props) {
    return (
        <>
            <Head title="Transfers" />
            <PageHeader
                title="Transfers"
                description="Stock sent between branches: it leaves when sent and arrives when the other branch receives it."
                actions={
                    canSend && (
                        <Button size="sm" asChild>
                            <Link href={create()}>Send stock</Link>
                        </Button>
                    )
                }
            />

            <Panel>
                <PanelToolbar>
                    <nav aria-label="Direction" className="flex gap-1">
                        {TABS.map((tab) => (
                            <Button
                                key={tab.value}
                                variant={
                                    direction === tab.value
                                        ? 'secondary'
                                        : 'ghost'
                                }
                                size="sm"
                                asChild
                            >
                                <Link
                                    href={index({
                                        query: { direction: tab.value },
                                    })}
                                >
                                    {tab.label}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </PanelToolbar>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Transfer</TableHead>
                            <TableHead>
                                {direction === 'incoming' ? 'From' : 'To'}
                            </TableHead>
                            <TableHead>Sent</TableHead>
                            <TableHead className="text-right">Units</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {transfers.length === 0 ? (
                            <TableEmpty colSpan={5}>No transfers.</TableEmpty>
                        ) : (
                            transfers.map((transfer) => (
                                <TableRow key={transfer.id}>
                                    <TableCell>
                                        <Link
                                            href={show(transfer.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {transfer.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {direction === 'incoming'
                                            ? transfer.from
                                            : transfer.to}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {new Date(
                                            transfer.sentAt,
                                        ).toLocaleString()}{' '}
                                        <span className="text-muted-foreground">
                                            by {transfer.sentBy}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {transfer.units}{' '}
                                        <span className="text-muted-foreground">
                                            ({transfer.lines}{' '}
                                            {transfer.lines === 1
                                                ? 'batch'
                                                : 'batches'}
                                            )
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={statusTone(transfer.status)}
                                        >
                                            {transfer.status.replace('_', ' ')}
                                        </StatusBadge>
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
