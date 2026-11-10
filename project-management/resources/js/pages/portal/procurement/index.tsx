import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
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
import { formatMoney } from '@/lib/money';
import { formatDate, statusTone } from '@/lib/status';
import { create, index, show } from '@/routes/procurement';
import type { Option } from '@/types';

type Row = {
    id: string;
    number: string;
    title: string;
    project: string;
    requester: string;
    status: string;
    statusLabel: string;
    estimateMinor: number;
    currency: string;
    neededBy: string | null;
};

type Props = {
    requests: {
        data: Row[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    statuses: Option[];
    status: string | null;
    canRequest: boolean;
};

/** Everything being bought, from request to payment. */
export default function ProcurementIndex({
    requests,
    statuses,
    status,
    canRequest,
}: Props) {
    return (
        <>
            <Head title="Procurement" />
            <PageHeader
                title="Procurement"
                description="From a request to buy, through quotes and a purchase order, to goods received and paid."
                actions={
                    canRequest && (
                        <Button asChild size="sm">
                            <Link href={create()}>New request</Link>
                        </Button>
                    )
                }
            />

            <nav
                aria-label="Filter by status"
                className="mb-4 flex flex-wrap gap-3 text-sm"
            >
                <Link
                    href={index()}
                    className={status === null ? 'font-medium' : 'underline'}
                >
                    All
                </Link>
                {statuses.map((option) => (
                    <Link
                        key={option.value}
                        href={index({ query: { status: option.value } })}
                        className={
                            status === option.value
                                ? 'font-medium'
                                : 'underline'
                        }
                    >
                        {option.label}
                    </Link>
                ))}
            </nav>

            <Panel>
                {requests.data.length === 0 ? (
                    <PanelBody>
                        <p className="text-sm text-muted-foreground">
                            No purchase requests here yet.
                        </p>
                    </PanelBody>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Request</TableHead>
                                <TableHead>Project</TableHead>
                                <TableHead>Requested by</TableHead>
                                <TableHead>Needed by</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Estimate
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {requests.data.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <Link
                                            href={show({
                                                purchaseRequest: row.id,
                                            })}
                                            className="font-medium underline-offset-2 hover:underline"
                                        >
                                            {row.number}
                                        </Link>
                                        <span className="block text-xs text-muted-foreground">
                                            {row.title}
                                        </span>
                                    </TableCell>
                                    <TableCell>{row.project}</TableCell>
                                    <TableCell>{row.requester}</TableCell>
                                    <TableCell>
                                        {row.neededBy
                                            ? formatDate(row.neededBy)
                                            : 'Not set'}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={statusTone(row.status)}
                                        >
                                            {row.statusLabel}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            row.estimateMinor,
                                            row.currency,
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            {(requests.prev_page_url || requests.next_page_url) && (
                <div className="mt-4 flex justify-between text-sm">
                    {requests.prev_page_url ? (
                        <Link
                            href={requests.prev_page_url}
                            className="underline"
                        >
                            Newer
                        </Link>
                    ) : (
                        <span />
                    )}
                    {requests.next_page_url && (
                        <Link
                            href={requests.next_page_url}
                            className="underline"
                        >
                            Older
                        </Link>
                    )}
                </div>
            )}
        </>
    );
}
