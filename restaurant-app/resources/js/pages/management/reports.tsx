import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { OpenBranch } from '@/components/open-branch';
import { PeriodFilter, Stat, periodLabel } from '@/components/report-bits';
import { formatMoney } from '@/lib/money';
import { reports } from '@/routes/management';

type BranchRef = { id: string; name: string };

type Line = {
    id: string;
    branchId: string;
    branch: string;
    at: string | null;
};

type Props = {
    filters: { from: string; to: string; branch: string | null };
    branchOptions: BranchRef[];
    shownBranches: BranchRef[];
    currency: string;
    byBranch: {
        id: string;
        name: string;
        discounts: number;
        discountMinor: number;
        refunds: number;
        refundedMinor: number;
        cancelledOrders: number;
        voidedLines: number;
        tillsClosed: number;
        tillsOff: number;
        tillDifferenceMinor: number;
        writtenOffMinor: number;
        pendingHandovers: number;
        pendingHandoverMinor: number;
    }[];
    discounts: (Line & {
        number: string;
        amountMinor: number;
        reason: string | null;
        by: string;
    })[];
    refunds: (Line & {
        number: string;
        amountMinor: number;
        reason: string | null;
        by: string | null;
    })[];
    cancelledOrders: (Line & { number: string; reason: string | null })[];
    voidedLines: (Line & {
        order: string;
        item: string;
        quantity: number;
        reason: string | null;
        by: string | null;
    })[];
    tills: (Line & {
        number: string;
        cashier: string;
        expectedMinor: number;
        countedMinor: number;
        differenceMinor: number;
        note: string | null;
    })[];
    handovers: (Line & {
        number: string;
        status: 'pending' | 'received';
        till: string | null;
        amountMinor: number;
        handedBy: string;
        handedAt: string;
        receivedBy: string | null;
        receivedMinor: number | null;
        differenceMinor: number | null;
        note: string | null;
        receivingNote: string | null;
    })[];
    receivedTotalMinor: number;
    writeOffs: (Line & {
        item: string;
        quantity: number;
        costMinor: number;
        type: string;
        reason: string | null;
        note: string | null;
        by: string;
    })[];
};

const NUM = 'py-2 text-right tabular-nums';
const HEAD = 'py-2 font-medium';

function when(iso: string | null): string {
    return iso === null ? '' : new Date(iso).toLocaleString();
}

/** A list of what needs a second look, or a line saying there was none. */
function Section({
    title,
    description,
    empty,
    count,
    children,
}: {
    title: string;
    description?: string;
    empty: string;
    count: number;
    children: ReactNode;
}) {
    return (
        <Panel>
            <PanelHeader title={title} description={description} />
            <PanelBody className="overflow-x-auto">
                {count === 0 ? (
                    <p className="text-sm text-muted-foreground">{empty}</p>
                ) : (
                    children
                )}
            </PanelBody>
        </Panel>
    );
}

/** One row of a detail list: when, where, what, how much, why and who. */
function Row({
    line,
    what,
    amount,
    why,
    who,
    danger = false,
}: {
    line: Line;
    what: ReactNode;
    amount?: string;
    why?: string | null;
    who?: string | null;
    danger?: boolean;
}) {
    return (
        <tr>
            <td className="py-2 pr-3 whitespace-nowrap text-muted-foreground tabular-nums">
                {when(line.at)}
            </td>
            <td className="py-2 pr-3">{line.branch}</td>
            <td className="py-2 pr-3">{what}</td>
            <td
                className={`${NUM} pr-3 ${danger ? 'font-medium text-red-700' : ''}`}
            >
                {amount}
            </td>
            <td className="py-2 pr-3 text-muted-foreground">{why || '—'}</td>
            <td className="py-2">{who}</td>
        </tr>
    );
}

function Rows({ children }: { children: ReactNode }) {
    return (
        <table className="w-full min-w-[40rem] text-sm">
            <thead className="text-left text-muted-foreground">
                <tr>
                    <th className={HEAD}>When</th>
                    <th className={HEAD}>Branch</th>
                    <th className={HEAD}>What</th>
                    <th className={`${HEAD} text-right`}>Amount</th>
                    <th className={HEAD}>Why</th>
                    <th className={HEAD}>By</th>
                </tr>
            </thead>
            <tbody className="divide-y">{children}</tbody>
        </table>
    );
}

/**
 * The controls across the branches over a period: discounts, refunds,
 * cancelled orders and voided dishes, tills over or short, cash not yet
 * received, and drinks written off. Each list shows the latest 100.
 */
export default function ManagementReports(props: Props) {
    const money = (minor: number) => formatMoney(minor, props.currency);
    const sum = (pick: (row: Props['byBranch'][number]) => number) =>
        props.byBranch.reduce((total, row) => total + pick(row), 0);
    const tillDifference = sum((row) => row.tillDifferenceMinor);

    return (
        <>
            <Head title="Reports" />
            <PageHeader
                title="Reports"
                description={`${props.filters.branch ? (props.shownBranches[0]?.name ?? '') : 'All branches'}, ${periodLabel(props.filters.from, props.filters.to)}`}
            />

            <Panel className="mb-4">
                <PeriodFilter
                    action={reports.form()}
                    from={props.filters.from}
                    to={props.filters.to}
                    branches={props.branchOptions}
                    branch={props.filters.branch}
                />
            </Panel>

            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat
                    label="Discounts given"
                    value={money(sum((row) => row.discountMinor))}
                    note={`${sum((row) => row.discounts)} bills`}
                />
                <Stat
                    label="Refunds"
                    value={money(sum((row) => row.refundedMinor))}
                    note={`${sum((row) => row.refunds)} bills voided`}
                />
                <Stat
                    label="Tills over / short"
                    value={money(tillDifference)}
                    tone={tillDifference < 0 ? 'danger' : undefined}
                    note={`${sum((row) => row.tillsOff)} of ${sum((row) => row.tillsClosed)} closed tills off`}
                />
                <Stat
                    label="Cash not yet received"
                    value={money(sum((row) => row.pendingHandoverMinor))}
                    tone={
                        sum((row) => row.pendingHandovers) > 0
                            ? 'danger'
                            : undefined
                    }
                    note={`${sum((row) => row.pendingHandovers)} handovers waiting · ${money(props.receivedTotalMinor)} received in the period`}
                />
            </div>

            <Panel className="mb-4">
                <PanelHeader title="By branch" />
                <PanelBody className="overflow-x-auto">
                    <table className="w-full min-w-[48rem] text-sm">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className={HEAD}>Branch</th>
                                <th className={`${HEAD} text-right`}>
                                    Discounts
                                </th>
                                <th className={`${HEAD} text-right`}>
                                    Refunds
                                </th>
                                <th className={`${HEAD} text-right`}>
                                    Orders cancelled
                                </th>
                                <th className={`${HEAD} text-right`}>
                                    Dishes voided
                                </th>
                                <th className={`${HEAD} text-right`}>
                                    Tills over / short
                                </th>
                                <th className={`${HEAD} text-right`}>
                                    Cash waiting
                                </th>
                                <th className={`${HEAD} text-right`}>
                                    Drinks written off
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {props.byBranch.map((row) => (
                                <tr key={row.id}>
                                    <td className="py-2">
                                        <OpenBranch
                                            branchId={row.id}
                                            name={row.name}
                                        />
                                    </td>
                                    <td className={NUM}>
                                        {money(row.discountMinor)}
                                    </td>
                                    <td className={NUM}>
                                        {money(row.refundedMinor)}
                                    </td>
                                    <td className={NUM}>
                                        {row.cancelledOrders}
                                    </td>
                                    <td className={NUM}>{row.voidedLines}</td>
                                    <td
                                        className={`${NUM} ${row.tillDifferenceMinor < 0 ? 'font-medium text-red-700' : ''}`}
                                    >
                                        {money(row.tillDifferenceMinor)}
                                    </td>
                                    <td
                                        className={`${NUM} ${row.pendingHandovers > 0 ? 'font-medium text-red-700' : ''}`}
                                    >
                                        {money(row.pendingHandoverMinor)}
                                    </td>
                                    <td className={NUM}>
                                        {money(row.writtenOffMinor)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </PanelBody>
            </Panel>

            <div className="grid gap-4">
                <Section
                    title="Cash handovers"
                    description="Waiting to be received, and received with a difference."
                    empty="All cash handed over was received as handed."
                    count={props.handovers.length}
                >
                    <Rows>
                        {props.handovers.map((handover) => (
                            <Row
                                key={handover.id}
                                line={{ ...handover, at: handover.handedAt }}
                                what={
                                    <span className="flex flex-wrap items-center gap-2">
                                        {handover.number} · shift{' '}
                                        {handover.till}
                                        <StatusBadge
                                            tone={
                                                handover.status === 'pending'
                                                    ? 'warning'
                                                    : 'danger'
                                            }
                                        >
                                            {handover.status === 'pending'
                                                ? 'Waiting'
                                                : `Received ${money(handover.receivedMinor ?? 0)}`}
                                        </StatusBadge>
                                    </span>
                                }
                                amount={
                                    handover.status === 'pending'
                                        ? money(handover.amountMinor)
                                        : money(handover.differenceMinor ?? 0)
                                }
                                danger
                                why={handover.receivingNote ?? handover.note}
                                who={
                                    handover.receivedBy
                                        ? `${handover.handedBy} → ${handover.receivedBy}`
                                        : handover.handedBy
                                }
                            />
                        ))}
                    </Rows>
                </Section>

                <Section
                    title="Tills over or short"
                    empty="Every till closed in the period counted what it should."
                    count={props.tills.length}
                >
                    <Rows>
                        {props.tills.map((till) => (
                            <Row
                                key={till.id}
                                line={till}
                                what={`Shift ${till.number}: expected ${money(till.expectedMinor)}, counted ${money(till.countedMinor)}`}
                                amount={money(till.differenceMinor)}
                                danger={till.differenceMinor < 0}
                                why={till.note}
                                who={till.cashier}
                            />
                        ))}
                    </Rows>
                </Section>

                <Section
                    title="Discounts"
                    description="Largest first."
                    empty="No discounts given."
                    count={props.discounts.length}
                >
                    <Rows>
                        {props.discounts.map((bill) => (
                            <Row
                                key={bill.id}
                                line={bill}
                                what={`Bill ${bill.number}`}
                                amount={money(bill.amountMinor)}
                                why={bill.reason}
                                who={bill.by}
                            />
                        ))}
                    </Rows>
                </Section>

                <Section
                    title="Refunds"
                    empty="No bills voided."
                    count={props.refunds.length}
                >
                    <Rows>
                        {props.refunds.map((bill) => (
                            <Row
                                key={bill.id}
                                line={bill}
                                what={`Bill ${bill.number}`}
                                amount={money(bill.amountMinor)}
                                danger
                                why={bill.reason}
                                who={bill.by}
                            />
                        ))}
                    </Rows>
                </Section>

                <Section
                    title="Cancelled orders and voided dishes"
                    empty="No orders cancelled and no dishes voided."
                    count={
                        props.cancelledOrders.length + props.voidedLines.length
                    }
                >
                    <Rows>
                        {props.cancelledOrders.map((order) => (
                            <Row
                                key={order.id}
                                line={order}
                                what={`Order ${order.number} cancelled`}
                                why={order.reason}
                            />
                        ))}
                        {props.voidedLines.map((line) => (
                            <Row
                                key={line.id}
                                line={line}
                                what={`${line.quantity} × ${line.item} voided on order ${line.order}`}
                                why={line.reason}
                                who={line.by}
                            />
                        ))}
                    </Rows>
                </Section>

                <Section
                    title="Drinks written off"
                    description="Counts that found less, and drinks damaged, expired or missing, at average cost."
                    empty="No drinks written off."
                    count={props.writeOffs.length}
                >
                    <Rows>
                        {props.writeOffs.map((movement) => (
                            <Row
                                key={movement.id}
                                line={movement}
                                what={`${movement.quantity} × ${movement.item}`}
                                amount={money(movement.costMinor)}
                                danger
                                why={
                                    [movement.reason, movement.note]
                                        .filter(Boolean)
                                        .join(' · ') ||
                                    (movement.type === 'count'
                                        ? 'Counted'
                                        : null)
                                }
                                who={movement.by}
                            />
                        ))}
                    </Rows>
                </Section>
            </div>
        </>
    );
}
