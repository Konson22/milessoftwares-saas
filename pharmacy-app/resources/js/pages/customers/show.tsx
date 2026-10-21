import { Head, Link, usePage } from '@inertiajs/react';
import { ReceiptText } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
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
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import { edit } from '@/routes/customers';
import { show as showSale } from '@/routes/sales';

type Props = {
    customer: {
        id: string;
        number: string;
        name: string;
        phone: string | null;
        email: string | null;
        address: string | null;
        active: boolean;
    };
    totals: {
        purchases: number;
        spentMinor: number;
        lastBoughtAt: string | null;
    };
    sales: {
        id: string;
        number: string;
        status: string;
        soldAt: string;
        soldBy: string;
        totalMinor: number;
        returnedMinor: number;
        items: string[];
        /** Sales of this branch open from here. */
        here: boolean;
    }[];
    canManage: boolean;
};

/**
 * A registered customer and everything they bought, in every branch.
 */
export default function Customer({
    customer,
    totals,
    sales,
    canManage,
}: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title={customer.name} />
            <PageHeader
                title={customer.name}
                description={[customer.number, customer.phone, customer.email]
                    .filter(Boolean)
                    .join(' · ')}
                actions={
                    canManage ? (
                        <Button variant="outline" asChild>
                            <Link href={edit(customer.id)}>Edit</Link>
                        </Button>
                    ) : undefined
                }
            />

            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <StatCard label="Purchases" value={String(totals.purchases)} />
                <StatCard label="Spent" value={money(totals.spentMinor)} />
                <StatCard
                    label="Last bought"
                    value={
                        totals.lastBoughtAt
                            ? new Date(totals.lastBoughtAt).toLocaleDateString()
                            : '—'
                    }
                />
            </div>

            <Panel>
                <PanelHeader
                    title="Sales history"
                    description={customer.address ?? undefined}
                />
                {sales.length === 0 ? (
                    <EmptyState icon={ReceiptText} title="Nothing bought yet" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Sale</TableHead>
                                <TableHead>When</TableHead>
                                <TableHead>Items</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sales.map((sale) => (
                                <TableRow key={sale.id}>
                                    <TableCell>
                                        {sale.here ? (
                                            <Link
                                                href={showSale(sale.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {sale.number}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">
                                                {sale.number}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {new Date(sale.soldAt).toLocaleString()}{' '}
                                        · {sale.soldBy}
                                    </TableCell>
                                    <TableCell className="max-w-xs truncate">
                                        {sale.items.join(', ')}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={statusTone(sale.status)}
                                        >
                                            {sale.status}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(sale.totalMinor)}
                                        {sale.returnedMinor > 0 && (
                                            <span className="block text-xs text-muted-foreground">
                                                {money(sale.returnedMinor)}{' '}
                                                returned
                                            </span>
                                        )}
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
