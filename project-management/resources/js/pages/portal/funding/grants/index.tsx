import { Head, Link, router } from '@inertiajs/react';
import { HandCoins, Plus } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader, PanelToolbar } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
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
import { Select } from '@/components/field';
import { formatMoney } from '@/lib/money';
import { formatDate, statusLabel, statusTone } from '@/lib/status';
import {
    create,
    exportMethod as exportGrants,
    index,
    show,
} from '@/routes/grants';
import type { Option } from '@/types';

type GrantRow = {
    id: string;
    reference: string;
    title: string;
    funder: string;
    status: string;
    currency: string;
    amountMinor: number;
    allocatedMinor: number;
    receivedMinor: number;
    endOn: string | null;
};

type ReportDue = {
    id: string;
    grantId: string;
    grant: string;
    title: string;
    dueOn: string;
    status: string;
    overdue: boolean;
};

/**
 * Every grant from opportunity to closeout, active funding by currency,
 * and the reports funders are waiting for.
 */
export default function Grants({
    grants,
    activeTotals,
    reportsDue,
    statuses,
    filters,
    canManage,
}: {
    grants: GrantRow[];
    activeTotals: { currency: string; amountMinor: number; grants: number }[];
    reportsDue: ReportDue[];
    statuses: Option[];
    filters: { status: string };
    canManage: boolean;
}) {
    const add = canManage && (
        <Button size="sm" asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Record a grant
            </Link>
        </Button>
    );

    return (
        <>
            <Head title="Grants" />
            <PageHeader
                title="Grants"
                description="From opportunity to closeout. One grant can fund several projects; a project can draw on several grants."
                actions={
                    <span className="flex items-center gap-3">
                        <a
                            href={exportGrants.url()}
                            className="text-sm underline"
                        >
                            Download CSV
                        </a>
                        {add}
                    </span>
                }
            />

            {activeTotals.length > 0 && (
                <div className="mb-4 grid gap-3 sm:grid-cols-3">
                    {activeTotals.map((total) => (
                        <StatCard
                            key={total.currency}
                            label={`Active funding in ${total.currency}`}
                            value={formatMoney(
                                total.amountMinor,
                                total.currency,
                            )}
                            hint={`${total.grants} ${total.grants === 1 ? 'grant' : 'grants'}`}
                            accent="emerald"
                        />
                    ))}
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-[3fr_1fr]">
                <Panel>
                    <PanelToolbar>
                        <label
                            htmlFor="status"
                            className="text-sm text-muted-foreground"
                        >
                            Status
                        </label>
                        <Select
                            id="status"
                            className="w-44"
                            value={filters.status}
                            placeholder="All"
                            options={statuses}
                            onChange={(event) =>
                                router.get(
                                    index({
                                        query: event.target.value
                                            ? { status: event.target.value }
                                            : {},
                                    }).url,
                                    {},
                                    { preserveState: true },
                                )
                            }
                        />
                    </PanelToolbar>
                    {grants.length === 0 ? (
                        <EmptyState
                            icon={HandCoins}
                            title="No grants here"
                            action={add}
                        >
                            Record opportunities and applications as they come,
                            and the agreement once awarded.
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Grant</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Allocated
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Received
                                    </TableHead>
                                    <TableHead>Ends</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {grants.map((grant) => (
                                    <TableRow key={grant.id}>
                                        <TableCell>
                                            <Link
                                                href={show({ grant: grant.id })}
                                                className="font-medium hover:underline"
                                            >
                                                {grant.reference}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                {grant.title} · {grant.funder}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                tone={statusTone(grant.status)}
                                            >
                                                {statusLabel(grant.status)}
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMoney(
                                                grant.amountMinor,
                                                grant.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right text-muted-foreground tabular-nums">
                                            {formatMoney(
                                                grant.allocatedMinor,
                                                grant.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right text-muted-foreground tabular-nums">
                                            {formatMoney(
                                                grant.receivedMinor,
                                                grant.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {grant.endOn
                                                ? formatDate(grant.endOn)
                                                : '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Reports owed"
                        description="Overdue, and due in the next 60 days."
                    />
                    {reportsDue.length === 0 ? (
                        <p className="px-3 py-3 text-sm text-muted-foreground">
                            Nothing due.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {reportsDue.map((report) => (
                                <li
                                    key={report.id}
                                    className="px-3 py-2 text-sm"
                                >
                                    <Link
                                        href={show({ grant: report.grantId })}
                                        className="font-medium hover:underline"
                                    >
                                        {report.title}
                                    </Link>
                                    <p
                                        className={
                                            report.overdue
                                                ? 'text-xs text-destructive'
                                                : 'text-xs text-muted-foreground'
                                        }
                                    >
                                        {report.grant} ·{' '}
                                        {report.overdue ? 'was due' : 'due'}{' '}
                                        {formatDate(report.dueOn)}
                                        {report.status ===
                                            'revision_requested' &&
                                            ' · revision requested'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
