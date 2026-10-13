import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { Amount } from '@/components/amount';
import { StatusBadge } from '@/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { sourceLabel, statusLabel, statusTone } from '@/lib/format';

export type DocumentRow = {
    id: string;
    number: string;
    party: string | null;
    source: string;
    date: string;
    dueOn: string | null;
    totalMinor: number;
    outstandingMinor: number;
    status: string;
};

/**
 * Bills or invoices: number, who, dates, amounts and status.
 */
export function DocumentsTable({
    rows,
    partyLabel,
    href,
    empty,
    showParty = true,
}: {
    rows: DocumentRow[];
    partyLabel: string;
    href: (id: string) => NonNullable<InertiaLinkProps['href']>;
    empty: string;
    showParty?: boolean;
}) {
    const columns = showParty ? 8 : 7;

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Number</TableHead>
                    {showParty && <TableHead>{partyLabel}</TableHead>}
                    <TableHead>Date</TableHead>
                    <TableHead>Due</TableHead>
                    <TableHead>From</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead className="text-right">Outstanding</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.length === 0 && (
                    <TableEmpty colSpan={columns}>{empty}</TableEmpty>
                )}
                {rows.map((row) => (
                    <TableRow key={row.id}>
                        <TableCell className="font-mono text-xs">
                            <Link
                                href={href(row.id)}
                                className="text-primary hover:underline"
                            >
                                {row.number}
                            </Link>
                        </TableCell>
                        {showParty && <TableCell>{row.party ?? '—'}</TableCell>}
                        <TableCell className="tabular-nums">
                            {row.date}
                        </TableCell>
                        <TableCell className="tabular-nums">
                            {row.dueOn ?? '—'}
                        </TableCell>
                        <TableCell>{sourceLabel(row.source)}</TableCell>
                        <TableCell>
                            <StatusBadge tone={statusTone(row.status)}>
                                {statusLabel(row.status)}
                            </StatusBadge>
                        </TableCell>
                        <TableCell className="text-right">
                            <Amount minor={row.totalMinor} />
                        </TableCell>
                        <TableCell className="text-right font-medium">
                            <Amount minor={row.outstandingMinor} blankZero />
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * Status filter tabs for bills and invoices.
 */
export function StatusTabs({
    statuses,
    current,
    href,
}: {
    statuses: { value: string; label: string }[];
    current: string;
    href: (status: string) => NonNullable<InertiaLinkProps['href']>;
}) {
    return (
        <nav
            aria-label="Status"
            className="flex flex-wrap gap-1 rounded-md bg-muted p-1"
        >
            {statuses.map((status) => (
                <Link
                    key={status.value}
                    href={href(status.value)}
                    preserveState
                    aria-current={status.value === current ? 'page' : undefined}
                    className={
                        status.value === current
                            ? 'rounded-sm bg-card px-3 py-1 text-sm font-medium shadow-xs'
                            : 'rounded-sm px-3 py-1 text-sm text-muted-foreground hover:text-foreground'
                    }
                >
                    {status.label}
                </Link>
            ))}
        </nav>
    );
}
