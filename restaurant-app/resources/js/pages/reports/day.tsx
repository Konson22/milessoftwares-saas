import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { formatMoney } from '@/lib/money';
import { show as showBill } from '@/routes/bills';
import { show as showOrder } from '@/routes/orders';
import { day as dayReport } from '@/routes/reports';

type Group = { name: string; count: number; totalMinor: number };

type Props = {
    day: string;
    takings: {
        count: number;
        totalMinor: number;
        averageMinor: number;
        discountMinor: number;
        refundedMinor: number;
    };
    byMethod: Group[];
    byCashier: Group[];
    byWaiter: Group[];
    bestSellers: { name: string; quantity: number }[];
    discounts: {
        id: string;
        number: string;
        discountMinor: number;
        reason: string | null;
        by: string;
    }[];
    voidedBills: {
        id: string;
        number: string;
        totalMinor: number;
        reason: string | null;
        by: string | null;
    }[];
    cancelledOrders: { id: string; number: string; reason: string | null }[];
    voidedLines: {
        id: string;
        order: string;
        orderId: string;
        name: string;
        quantity: number;
        reason: string | null;
        by: string | null;
    }[];
};

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border bg-card p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
        </div>
    );
}

function Section({
    title,
    empty,
    children,
}: {
    title: string;
    empty: boolean;
    children: ReactNode;
}) {
    return (
        <Panel className="break-inside-avoid">
            <PanelHeader title={title} />
            <PanelBody>
                {empty ? (
                    <p className="text-sm text-muted-foreground">None.</p>
                ) : (
                    <ul className="divide-y text-sm">{children}</ul>
                )}
            </PanelBody>
        </Panel>
    );
}

function Row({ left, right }: { left: ReactNode; right: ReactNode }) {
    return (
        <li className="flex items-start justify-between gap-3 py-1.5">
            <span className="min-w-0">{left}</span>
            <span className="shrink-0 text-right tabular-nums">{right}</span>
        </li>
    );
}

/**
 * One day at the branch: takings and how they came in, what sold, and
 * everything given away or taken back.
 */
export default function DayReport(props: Props) {
    const { branch, currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const { takings } = props;
    const groups = (rows: Group[]) =>
        rows.map((row) => (
            <Row
                key={row.name}
                left={row.name}
                right={
                    <>
                        {money(row.totalMinor)}
                        <span className="block text-xs text-muted-foreground">
                            {row.count} bills
                        </span>
                    </>
                }
            />
        ));

    return (
        <>
            <Head title="Day report" />
            <PageHeader
                title="Day report"
                description={`${branch?.name ?? ''} · ${new Date(`${props.day}T00:00:00`).toLocaleDateString(undefined, { dateStyle: 'full' })}`}
                actions={
                    <Button
                        variant="outline"
                        size="sm"
                        className="print:hidden"
                        onClick={() => window.print()}
                    >
                        <Printer className="size-4" aria-hidden />
                        Print
                    </Button>
                }
            />

            <Panel className="mb-4 print:hidden">
                <PanelToolbar>
                    <Form
                        {...dayReport.form()}
                        className="flex flex-wrap items-end gap-2"
                    >
                        <div className="grid gap-1.5">
                            <label
                                htmlFor="day"
                                className="text-sm font-medium"
                            >
                                Day
                            </label>
                            <Input
                                id="day"
                                name="day"
                                type="date"
                                defaultValue={props.day}
                                className="w-44"
                            />
                        </div>
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>
            </Panel>

            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label="Takings" value={money(takings.totalMinor)} />
                <Stat label="Bills" value={String(takings.count)} />
                <Stat
                    label="Average bill"
                    value={money(takings.averageMinor)}
                />
                <Stat
                    label="Discounts given"
                    value={money(takings.discountMinor)}
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Section title="Paid by" empty={props.byMethod.length === 0}>
                    {groups(props.byMethod)}
                </Section>
                <Section
                    title="Best sellers"
                    empty={props.bestSellers.length === 0}
                >
                    {props.bestSellers.map((item) => (
                        <Row
                            key={item.name}
                            left={item.name}
                            right={`${item.quantity} sold`}
                        />
                    ))}
                </Section>
                <Section
                    title="Taken by cashier"
                    empty={props.byCashier.length === 0}
                >
                    {groups(props.byCashier)}
                </Section>
                <Section
                    title="Sold by waiter"
                    empty={props.byWaiter.length === 0}
                >
                    {groups(props.byWaiter)}
                </Section>
                <Section title="Discounts" empty={props.discounts.length === 0}>
                    {props.discounts.map((bill) => (
                        <Row
                            key={bill.id}
                            left={
                                <>
                                    <Link
                                        href={showBill({ bill: bill.id })}
                                        className="font-medium hover:underline"
                                    >
                                        Bill {bill.number}
                                    </Link>
                                    <span className="block text-xs text-muted-foreground">
                                        {bill.reason} · {bill.by}
                                    </span>
                                </>
                            }
                            right={`− ${money(bill.discountMinor)}`}
                        />
                    ))}
                </Section>
                <Section
                    title={`Refunds · ${money(takings.refundedMinor)}`}
                    empty={props.voidedBills.length === 0}
                >
                    {props.voidedBills.map((bill) => (
                        <Row
                            key={bill.id}
                            left={
                                <>
                                    <Link
                                        href={showBill({ bill: bill.id })}
                                        className="font-medium hover:underline"
                                    >
                                        Bill {bill.number}
                                    </Link>
                                    <span className="block text-xs text-muted-foreground">
                                        {bill.reason}
                                        {bill.by && ` · ${bill.by}`}
                                    </span>
                                </>
                            }
                            right={money(bill.totalMinor)}
                        />
                    ))}
                </Section>
                <Section
                    title="Lines voided"
                    empty={props.voidedLines.length === 0}
                >
                    {props.voidedLines.map((line) => (
                        <Row
                            key={line.id}
                            left={
                                <>
                                    {line.quantity} × {line.name}
                                    <span className="block text-xs text-muted-foreground">
                                        {line.reason}
                                        {line.by && ` · ${line.by}`}
                                    </span>
                                </>
                            }
                            right={
                                <Link
                                    href={showOrder({ order: line.orderId })}
                                    className="hover:underline"
                                >
                                    {line.order}
                                </Link>
                            }
                        />
                    ))}
                </Section>
                <Section
                    title="Orders cancelled"
                    empty={props.cancelledOrders.length === 0}
                >
                    {props.cancelledOrders.map((order) => (
                        <Row
                            key={order.id}
                            left={
                                <Link
                                    href={showOrder({ order: order.id })}
                                    className="font-medium hover:underline"
                                >
                                    {order.number}
                                </Link>
                            }
                            right={
                                <span className="text-muted-foreground">
                                    {order.reason}
                                </span>
                            }
                        />
                    ))}
                </Section>
            </div>
        </>
    );
}
