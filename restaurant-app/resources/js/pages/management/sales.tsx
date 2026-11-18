import { Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { OpenBranch } from '@/components/open-branch';
import { PeriodFilter, Stat, periodLabel } from '@/components/report-bits';
import { formatMoney } from '@/lib/money';
import { sales } from '@/routes/management';

type BranchRef = { id: string; name: string };

type Props = {
    filters: { from: string; to: string; branch: string | null };
    branchOptions: BranchRef[];
    shownBranches: BranchRef[];
    currency: string;
    byBranch: {
        id: string;
        name: string;
        grossMinor: number;
        discountMinor: number;
        salesMinor: number;
        refundedMinor: number;
        netMinor: number;
        bills: number;
        orders: number;
        averageOrderMinor: number;
        cashMinor: number;
        nonCashMinor: number;
    }[];
    byMethod: {
        method: string;
        name: string;
        byBranch: Record<string, number>;
        totalMinor: number;
    }[];
    byDay: {
        day: string;
        byBranch: Record<string, number>;
        netMinor: number;
    }[];
    bestSellers: {
        id: string;
        name: string;
        byBranch: Record<string, number>;
        quantity: number;
    }[];
};

const NUM = 'py-2 text-right tabular-nums';
const HEAD = 'py-2 font-medium';

/**
 * Sales across the branches over a period: each branch's takings, how they
 * were paid, day by day and what sold. Same figures as the dashboard.
 */
export default function ManagementSales(props: Props) {
    const money = (minor: number) => formatMoney(minor, props.currency);
    const branches = props.shownBranches;
    const sum = (pick: (row: Props['byBranch'][number]) => number) =>
        props.byBranch.reduce((total, row) => total + pick(row), 0);
    const orders = sum((row) => row.orders);
    const peak = Math.max(1, ...props.byDay.map((day) => day.netMinor));

    return (
        <>
            <Head title="Sales" />
            <PageHeader
                title="Sales"
                description={`${props.filters.branch ? (branches[0]?.name ?? '') : 'All branches'}, ${periodLabel(props.filters.from, props.filters.to)}`}
            />

            <Panel className="mb-4">
                <PeriodFilter
                    action={sales.form()}
                    from={props.filters.from}
                    to={props.filters.to}
                    branches={props.branchOptions}
                    branch={props.filters.branch}
                />
            </Panel>

            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat
                    label="Net sales"
                    value={money(sum((row) => row.netMinor))}
                    note={`${money(sum((row) => row.refundedMinor))} refunded`}
                />
                <Stat
                    label="Orders paid"
                    value={String(orders)}
                    note={`${sum((row) => row.bills)} bills`}
                />
                <Stat
                    label="Average order"
                    value={money(
                        orders === 0
                            ? 0
                            : Math.round(
                                  sum(
                                      (row) =>
                                          row.averageOrderMinor * row.orders,
                                  ) / orders,
                              ),
                    )}
                />
                <Stat
                    label="Discounts given"
                    value={money(sum((row) => row.discountMinor))}
                    note={`on ${money(sum((row) => row.grossMinor))} before discounts`}
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
                                    Before discounts
                                </th>
                                <th className={`${HEAD} text-right`}>
                                    Discounts
                                </th>
                                <th className={`${HEAD} text-right`}>Sales</th>
                                <th className={`${HEAD} text-right`}>
                                    Refunds
                                </th>
                                <th className={`${HEAD} text-right`}>Net</th>
                                <th className={`${HEAD} text-right`}>Orders</th>
                                <th className={`${HEAD} text-right`}>
                                    Average
                                </th>
                                <th className={`${HEAD} text-right`}>Cash</th>
                                <th className={`${HEAD} text-right`}>
                                    Other means
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
                                        {money(row.grossMinor)}
                                    </td>
                                    <td className={NUM}>
                                        {money(row.discountMinor)}
                                    </td>
                                    <td className={NUM}>
                                        {money(row.salesMinor)}
                                    </td>
                                    <td className={NUM}>
                                        {money(row.refundedMinor)}
                                    </td>
                                    <td className={`${NUM} font-semibold`}>
                                        {money(row.netMinor)}
                                    </td>
                                    <td className={NUM}>{row.orders}</td>
                                    <td className={NUM}>
                                        {money(row.averageOrderMinor)}
                                    </td>
                                    <td className={NUM}>
                                        {money(row.cashMinor)}
                                    </td>
                                    <td className={NUM}>
                                        {money(row.nonCashMinor)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        {props.byBranch.length > 1 && (
                            <tfoot className="border-t font-semibold">
                                <tr>
                                    <td className="py-2">All</td>
                                    <td className={NUM}>
                                        {money(sum((row) => row.grossMinor))}
                                    </td>
                                    <td className={NUM}>
                                        {money(sum((row) => row.discountMinor))}
                                    </td>
                                    <td className={NUM}>
                                        {money(sum((row) => row.salesMinor))}
                                    </td>
                                    <td className={NUM}>
                                        {money(sum((row) => row.refundedMinor))}
                                    </td>
                                    <td className={NUM}>
                                        {money(sum((row) => row.netMinor))}
                                    </td>
                                    <td className={NUM}>{orders}</td>
                                    <td className={NUM} />
                                    <td className={NUM}>
                                        {money(sum((row) => row.cashMinor))}
                                    </td>
                                    <td className={NUM}>
                                        {money(sum((row) => row.nonCashMinor))}
                                    </td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </PanelBody>
            </Panel>

            <div className="mb-4 grid gap-4 lg:grid-cols-2">
                <Panel>
                    <PanelHeader title="How it was paid" />
                    <PanelBody className="overflow-x-auto">
                        {props.byMethod.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing paid in this period.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="text-left text-muted-foreground">
                                    <tr>
                                        <th className={HEAD}>Method</th>
                                        {branches.length > 1 &&
                                            branches.map((branch) => (
                                                <th
                                                    key={branch.id}
                                                    className={`${HEAD} text-right`}
                                                >
                                                    {branch.name}
                                                </th>
                                            ))}
                                        <th className={`${HEAD} text-right`}>
                                            Total
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {props.byMethod.map((method) => (
                                        <tr key={method.method}>
                                            <td className="py-2">
                                                {method.name}
                                            </td>
                                            {branches.length > 1 &&
                                                branches.map((branch) => (
                                                    <td
                                                        key={branch.id}
                                                        className={NUM}
                                                    >
                                                        {money(
                                                            method.byBranch[
                                                                branch.id
                                                            ] ?? 0,
                                                        )}
                                                    </td>
                                                ))}
                                            <td
                                                className={`${NUM} font-medium`}
                                            >
                                                {money(method.totalMinor)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Best sellers"
                        description="By quantity on bills still paid."
                    />
                    <PanelBody className="overflow-x-auto">
                        {props.bestSellers.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing sold in this period.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="text-left text-muted-foreground">
                                    <tr>
                                        <th className={HEAD}>Item</th>
                                        {branches.length > 1 &&
                                            branches.map((branch) => (
                                                <th
                                                    key={branch.id}
                                                    className={`${HEAD} text-right`}
                                                >
                                                    {branch.name}
                                                </th>
                                            ))}
                                        <th className={`${HEAD} text-right`}>
                                            Sold
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {props.bestSellers.map((item) => (
                                        <tr key={item.id}>
                                            <td className="py-2">
                                                {item.name}
                                            </td>
                                            {branches.length > 1 &&
                                                branches.map((branch) => (
                                                    <td
                                                        key={branch.id}
                                                        className={NUM}
                                                    >
                                                        {item.byBranch[
                                                            branch.id
                                                        ] ?? 0}
                                                    </td>
                                                ))}
                                            <td
                                                className={`${NUM} font-medium`}
                                            >
                                                {item.quantity}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </PanelBody>
                </Panel>
            </div>

            <Panel>
                <PanelHeader title="Net sales by day" />
                <PanelBody className="overflow-x-auto">
                    <table className="w-full min-w-[36rem] text-sm">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className={HEAD}>Day</th>
                                <th className={`${HEAD} w-1/3`} />
                                {branches.length > 1 &&
                                    branches.map((branch) => (
                                        <th
                                            key={branch.id}
                                            className={`${HEAD} text-right`}
                                        >
                                            {branch.name}
                                        </th>
                                    ))}
                                <th className={`${HEAD} text-right`}>Net</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {props.byDay.map((day) => (
                                <tr key={day.day}>
                                    <td className="py-2 whitespace-nowrap text-muted-foreground tabular-nums">
                                        {day.day}
                                    </td>
                                    <td className="py-2 pr-3">
                                        <span className="block h-3 rounded-sm bg-muted">
                                            <span
                                                className="block h-3 rounded-sm bg-primary"
                                                style={{
                                                    width: `${Math.max(0, (day.netMinor / peak) * 100)}%`,
                                                }}
                                            />
                                        </span>
                                    </td>
                                    {branches.length > 1 &&
                                        branches.map((branch) => (
                                            <td key={branch.id} className={NUM}>
                                                {money(
                                                    day.byBranch[branch.id] ??
                                                        0,
                                                )}
                                            </td>
                                        ))}
                                    <td className={`${NUM} font-medium`}>
                                        {money(day.netMinor)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </PanelBody>
            </Panel>
        </>
    );
}
