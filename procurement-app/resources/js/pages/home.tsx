import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardList,
    FileSearch,
    PackageCheck,
    Plus,
    Scale,
    ShoppingCart,
} from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import { Status } from '@/components/fields';
import { documentUrl } from '@/lib/links';
import { formatDate, minor, money } from '@/lib/procurement';
import { index as approvals } from '@/routes/approvals';
import { index as orders, show as order } from '@/routes/orders';
import {
    create as newRequisition,
    index as requisitions,
    show as requisition,
} from '@/routes/requisitions';
import { index as sourcing } from '@/routes/sourcing';

type Awaiting = {
    type: string;
    typeLabel: string;
    id: string;
    number: string;
    title: string;
    amount: string | null;
    step: string;
};

type Props = {
    currency: string;
    decimals: number;
    awaitingMe: Awaiting[];
    myRequisitions: {
        id: string;
        number: string;
        title: string;
        status: string;
        total: string;
        currency: string;
    }[];
    figures: {
        pendingRequisitions: number;
        approvedUnbought: number;
        activeSourcing: number;
        awaitingEvaluation: number;
        ordersAwaitingDelivery: number;
        partialReceipts: number;
        overdueOrders: number;
        overdueRequisitions: number;
        committedMinor: number;
        invoicedMinor: number;
        paidExternallyMinor: number;
        sentToFinanceMinor: number;
    } | null;
    lateOrders: {
        id: string;
        number: string;
        supplier: string;
        deliveryDate: string;
        status: string;
    }[];
    canRequest: boolean;
};

export default function Home({
    currency,
    decimals,
    awaitingMe,
    myRequisitions,
    figures,
    lateOrders,
    canRequest,
}: Props) {
    const { workspace } = usePage().props;
    const base = (value: number) => `${currency} ${minor(value, decimals)}`;

    return (
        <>
            <Head title="Home" />
            <div className="grid gap-6">
                <PageHeader
                    title="Procurement"
                    description={`From purchase request to delivery for ${workspace?.name ?? 'this organization'}.`}
                    actions={
                        canRequest && (
                            <Button asChild>
                                <Link href={newRequisition()}>
                                    <Plus /> New requisition
                                </Link>
                            </Button>
                        )
                    }
                />

                {figures && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <StatCard
                                label="Requisitions awaiting approval"
                                value={figures.pendingRequisitions}
                                hint={`${figures.approvedUnbought} approved, not yet bought`}
                                icon={ClipboardList}
                                href={requisitions({
                                    query: { status: 'pending_approval' },
                                })}
                            />
                            <StatCard
                                label="Sourcing in progress"
                                value={figures.activeSourcing}
                                hint={`${figures.awaitingEvaluation} closed or in evaluation`}
                                icon={FileSearch}
                                accent="violet"
                                href={sourcing()}
                            />
                            <StatCard
                                label="Orders awaiting delivery"
                                value={figures.ordersAwaitingDelivery}
                                hint={`${figures.partialReceipts} partly received`}
                                icon={PackageCheck}
                                accent="emerald"
                                href={orders()}
                            />
                            <StatCard
                                label="Late"
                                value={
                                    figures.overdueOrders +
                                    figures.overdueRequisitions
                                }
                                hint={`${figures.overdueOrders} orders past delivery, ${figures.overdueRequisitions} requisitions past need date`}
                                icon={AlertTriangle}
                                accent="amber"
                            />
                        </div>

                        <Panel>
                            <PanelHeader
                                title="Money"
                                description="Kept apart on purpose: a commitment is not a bill, and a bill is not a payment."
                            />
                            <PanelBody>
                                <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                    <Figure
                                        label="Committed (open orders)"
                                        value={base(figures.committedMinor)}
                                    />
                                    <Figure
                                        label="Invoiced by suppliers"
                                        value={base(figures.invoicedMinor)}
                                    />
                                    <Figure
                                        label="Paid, recorded by hand"
                                        value={base(
                                            figures.paidExternallyMinor,
                                        )}
                                        hint="Noted here, not verified. Finance holds the real payments."
                                    />
                                    <Figure
                                        label="Sent to Finance"
                                        value={base(figures.sentToFinanceMinor)}
                                    />
                                </dl>
                            </PanelBody>
                        </Panel>
                    </>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Panel>
                        <PanelHeader
                            title="Waiting for you"
                            actions={
                                <Link
                                    href={approvals()}
                                    className="text-sm hover:underline"
                                >
                                    All approvals
                                </Link>
                            }
                        />
                        <PanelBody>
                            {awaitingMe.length === 0 ? (
                                <EmptyState
                                    icon={Scale}
                                    title="Nothing to decide"
                                />
                            ) : (
                                <ul className="divide-y">
                                    {awaitingMe.slice(0, 8).map((row) => (
                                        <li
                                            key={`${row.type}-${row.id}-${row.step}`}
                                            className="py-2"
                                        >
                                            <Link
                                                href={documentUrl(
                                                    row.type,
                                                    row.id,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {row.typeLabel} {row.number}
                                            </Link>
                                            <p className="text-sm text-muted-foreground">
                                                {row.title}
                                                {row.amount &&
                                                    ` · ${row.amount}`}{' '}
                                                · {row.step}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </PanelBody>
                    </Panel>

                    <Panel>
                        <PanelHeader title="My requisitions" />
                        <PanelBody>
                            {myRequisitions.length === 0 ? (
                                <EmptyState
                                    icon={ClipboardList}
                                    title="No open requisitions"
                                />
                            ) : (
                                <ul className="divide-y">
                                    {myRequisitions.map((row) => (
                                        <li
                                            key={row.id}
                                            className="flex items-center justify-between gap-3 py-2"
                                        >
                                            <div>
                                                <Link
                                                    href={requisition(row.id)}
                                                    className="font-medium hover:underline"
                                                >
                                                    {row.title}
                                                </Link>
                                                <p className="text-sm text-muted-foreground">
                                                    {row.number ?? 'Draft'} ·{' '}
                                                    {money(
                                                        row.total,
                                                        row.currency,
                                                    )}
                                                </p>
                                            </div>
                                            <Status value={row.status} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </PanelBody>
                    </Panel>
                </div>

                {lateOrders.length > 0 && (
                    <Panel>
                        <PanelHeader title="Late deliveries" />
                        <PanelBody>
                            <ul className="divide-y">
                                {lateOrders.map((row) => (
                                    <li
                                        key={row.id}
                                        className="flex items-center justify-between gap-3 py-2"
                                    >
                                        <div>
                                            <Link
                                                href={order(row.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {row.number}
                                            </Link>
                                            <p className="text-sm text-muted-foreground">
                                                {row.supplier} · due{' '}
                                                {formatDate(row.deliveryDate)}
                                            </p>
                                        </div>
                                        <ShoppingCart className="size-4 text-muted-foreground" />
                                    </li>
                                ))}
                            </ul>
                        </PanelBody>
                    </Panel>
                )}
            </div>
        </>
    );
}

function Figure({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <div className="grid gap-1">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-lg font-semibold tabular-nums">{value}</dd>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}
