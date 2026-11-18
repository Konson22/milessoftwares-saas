import { Head, Link, router } from '@inertiajs/react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Plus,
    Search,
    ShoppingCart,
    Truck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { formatMoney } from '@/lib/money';
import { formatDate, statusTone } from '@/lib/status';
import { create, index, show } from '@/routes/procurement';

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
    statuses: { value: string; label: string; count: number }[];
    total: number;
    overdue: number;
    status: string | null;
    search: string;
    canRequest: boolean;
};

/** Requests still to be bought: past their needed-by day is flagged. */
const OPEN = ['draft', 'submitted', 'approved'];

function isLate(row: Row): boolean {
    return (
        row.neededBy !== null &&
        OPEN.includes(row.status) &&
        new Date(`${row.neededBy}T23:59:59`) < new Date()
    );
}

/** Everything being bought, from request to payment. */
export default function ProcurementIndex({
    requests,
    statuses,
    total,
    overdue,
    status,
    search,
    canRequest,
}: Props) {
    const [query, setQuery] = useState(search);
    const count = (value: string) =>
        statuses.find((option) => option.value === value)?.count ?? 0;
    const go = (changes: { status?: string | null; q?: string }) =>
        router.get(
            index.url({
                query: {
                    status:
                        ('status' in changes ? changes.status : status) ??
                        undefined,
                    q: ('q' in changes ? changes.q : search) || undefined,
                },
            }),
            {},
            { preserveState: true, preserveScroll: true },
        );

    const figures: {
        label: string;
        value: number;
        icon: LucideIcon;
        status: string | null;
        tone: string;
    }[] = [
        {
            label: 'Awaiting approval',
            value: count('submitted'),
            icon: Clock,
            status: 'submitted',
            tone: 'text-amber-700 dark:text-amber-300',
        },
        {
            label: 'Approved, to order',
            value: count('approved'),
            icon: CheckCircle2,
            status: 'approved',
            tone: 'text-emerald-700 dark:text-emerald-300',
        },
        {
            label: 'Ordered',
            value: count('ordered'),
            icon: Truck,
            status: 'ordered',
            tone: 'text-sky-700 dark:text-sky-300',
        },
        {
            label: 'Past needed-by date',
            value: overdue,
            icon: AlertTriangle,
            status: null,
            tone:
                overdue > 0
                    ? 'text-red-700 dark:text-red-300'
                    : 'text-muted-foreground',
        },
    ];

    return (
        <>
            <Head title="Procurement" />
            <PageHeader
                title="Procurement"
                actions={
                    canRequest && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus /> New request
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="flex flex-col gap-4">
                <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {figures.map((figure) => {
                        const body = (
                            <>
                                <span className="flex items-center justify-between gap-2">
                                    <span className="text-xs font-medium text-muted-foreground">
                                        {figure.label}
                                    </span>
                                    <figure.icon
                                        className={`size-4 ${figure.tone}`}
                                        aria-hidden
                                    />
                                </span>
                                <span className="text-2xl font-semibold tabular-nums">
                                    {figure.value}
                                </span>
                            </>
                        );
                        const className =
                            'flex h-full flex-col gap-1 rounded-lg border bg-card p-4';

                        return (
                            <li key={figure.label}>
                                {figure.status ? (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            go({ status: figure.status })
                                        }
                                        aria-pressed={status === figure.status}
                                        className={`${className} w-full text-left transition hover:border-primary ${status === figure.status ? 'border-primary' : ''}`}
                                    >
                                        {body}
                                    </button>
                                ) : (
                                    <div className={className}>{body}</div>
                                )}
                            </li>
                        );
                    })}
                </ul>

                <Panel>
                    <div className="flex flex-col gap-3 border-b p-3 lg:flex-row lg:items-center lg:justify-between">
                        <nav
                            aria-label="Filter by status"
                            className="-mx-1 flex gap-1 overflow-x-auto px-1"
                        >
                            {[
                                { value: null, label: 'All', count: total },
                                ...statuses,
                            ].map((option) => {
                                const active = status === option.value;

                                return (
                                    <button
                                        key={option.value ?? 'all'}
                                        type="button"
                                        onClick={() =>
                                            go({ status: option.value })
                                        }
                                        aria-pressed={active}
                                        className={`inline-flex shrink-0 items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm whitespace-nowrap transition ${active ? 'bg-primary font-medium text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                                    >
                                        {option.label}
                                        <span
                                            className={`rounded px-1.5 text-xs tabular-nums ${active ? 'bg-primary-foreground text-primary' : 'bg-muted text-muted-foreground'}`}
                                        >
                                            {option.count}
                                        </span>
                                    </button>
                                );
                            })}
                        </nav>
                        <form
                            role="search"
                            className="relative lg:w-72"
                            onSubmit={(event) => {
                                event.preventDefault();
                                go({ q: query.trim() });
                            }}
                        >
                            <Search
                                className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <Input
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                onBlur={() =>
                                    query.trim() !== search &&
                                    go({ q: query.trim() })
                                }
                                placeholder="Search by number or title"
                                aria-label="Search requests"
                                className="pl-8"
                            />
                        </form>
                    </div>

                    {requests.data.length === 0 ? (
                        <EmptyState
                            icon={ShoppingCart}
                            title={
                                search || status
                                    ? 'No requests match'
                                    : 'No purchase requests yet'
                            }
                            action={
                                search || status ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => {
                                            setQuery('');
                                            go({ status: null, q: '' });
                                        }}
                                    >
                                        Show all requests
                                    </Button>
                                ) : (
                                    canRequest && (
                                        <Button asChild size="sm">
                                            <Link href={create()}>
                                                <Plus /> New request
                                            </Link>
                                        </Button>
                                    )
                                )
                            }
                        >
                            {search || status
                                ? 'Try another status or search.'
                                : 'When someone needs something bought for a project, the request starts here.'}
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Request</TableHead>
                                    <TableHead className="hidden md:table-cell">
                                        Project
                                    </TableHead>
                                    <TableHead className="hidden lg:table-cell">
                                        Requested by
                                    </TableHead>
                                    <TableHead>Needed by</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">
                                        Estimate
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {requests.data.map((row) => {
                                    const late = isLate(row);

                                    return (
                                        <TableRow
                                            key={row.id}
                                            className="cursor-pointer hover:bg-muted"
                                            onClick={() =>
                                                router.visit(
                                                    show({
                                                        purchaseRequest: row.id,
                                                    }),
                                                )
                                            }
                                        >
                                            <TableCell className="max-w-80">
                                                <Link
                                                    href={show({
                                                        purchaseRequest: row.id,
                                                    })}
                                                    onClick={(event) =>
                                                        event.stopPropagation()
                                                    }
                                                    className="block truncate font-medium hover:underline"
                                                >
                                                    {row.title}
                                                </Link>
                                                <span className="block text-xs text-muted-foreground">
                                                    {row.number}
                                                    <span className="md:hidden">
                                                        {' '}
                                                        · {row.project}
                                                    </span>
                                                </span>
                                            </TableCell>
                                            <TableCell className="hidden max-w-56 truncate md:table-cell">
                                                {row.project}
                                            </TableCell>
                                            <TableCell className="hidden lg:table-cell">
                                                {row.requester}
                                            </TableCell>
                                            <TableCell
                                                className={`whitespace-nowrap ${late ? 'font-medium text-red-700 dark:text-red-300' : ''}`}
                                            >
                                                {row.neededBy ? (
                                                    <>
                                                        {formatDate(
                                                            row.neededBy,
                                                        )}
                                                        {late && (
                                                            <span className="block text-xs">
                                                                Overdue
                                                            </span>
                                                        )}
                                                    </>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    tone={statusTone(
                                                        row.status,
                                                    )}
                                                >
                                                    {row.statusLabel}
                                                </StatusBadge>
                                            </TableCell>
                                            <TableCell className="text-right font-medium whitespace-nowrap tabular-nums">
                                                {formatMoney(
                                                    row.estimateMinor,
                                                    row.currency,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    )}

                    {(requests.prev_page_url || requests.next_page_url) && (
                        <div className="flex items-center justify-between gap-2 border-t p-3">
                            <Button
                                asChild={!!requests.prev_page_url}
                                size="sm"
                                variant="outline"
                                disabled={!requests.prev_page_url}
                            >
                                {requests.prev_page_url ? (
                                    <Link href={requests.prev_page_url}>
                                        <ChevronLeft /> Newer
                                    </Link>
                                ) : (
                                    <span>
                                        <ChevronLeft /> Newer
                                    </span>
                                )}
                            </Button>
                            <Button
                                asChild={!!requests.next_page_url}
                                size="sm"
                                variant="outline"
                                disabled={!requests.next_page_url}
                            >
                                {requests.next_page_url ? (
                                    <Link href={requests.next_page_url}>
                                        Older <ChevronRight />
                                    </Link>
                                ) : (
                                    <span>
                                        Older <ChevronRight />
                                    </span>
                                )}
                            </Button>
                        </div>
                    )}
                </Panel>
            </div>
        </>
    );
}
