import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { statusTone } from '@/lib/status';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { ORDER_STATUS } from '@/lib/purchasing';
import { cancel, index, ordered } from '@/routes/purchase-orders';
import { create as receive } from '@/routes/receipts';

type Props = {
    order: {
        id: string;
        number: string;
        supplier: string;
        status: string;
        expectedOn: string | null;
        notes: string | null;
        totalMinor: number;
        lines: {
            id: string;
            medicine: string;
            unit: string;
            pack: string | null;
            ordered: number;
            received: number;
            unitCostMinor: number;
        }[];
    };
    canManage: boolean;
    canReceive: boolean;
};

export default function ShowPurchaseOrder({
    order,
    canManage,
    canReceive,
}: Props) {
    const { currency, errors } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title={order.number} />
            <PageHeader
                title={order.number}
                description={`${order.supplier}${order.expectedOn ? ` · expected ${order.expectedOn}` : ''}${order.notes ? ` · ${order.notes}` : ''}`}
                badges={
                    <StatusBadge tone={statusTone(order.status)}>
                        {ORDER_STATUS[order.status] ?? order.status}
                    </StatusBadge>
                }
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>← Purchase orders</Link>
                        </Button>
                        {canManage && order.status === 'draft' && (
                            <Form {...ordered.form(order.id)}>
                                <Button type="submit">
                                    Mark as sent to supplier
                                </Button>
                            </Form>
                        )}
                        {canReceive && (
                            <Button asChild>
                                <Link
                                    href={receive({
                                        query: { order: order.id },
                                    })}
                                >
                                    Receive goods
                                </Link>
                            </Button>
                        )}
                        {canManage &&
                            ['draft', 'ordered'].includes(order.status) && (
                                <Form {...cancel.form(order.id)}>
                                    <Button type="submit" variant="outline">
                                        Cancel order
                                    </Button>
                                </Form>
                            )}
                    </div>
                }
            />
            {errors.status && (
                <p className="mb-4 text-sm text-destructive">{errors.status}</p>
            )}

            <Panel>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medicine</TableHead>
                            <TableHead className="text-right">
                                Ordered
                            </TableHead>
                            <TableHead className="text-right">
                                Received
                            </TableHead>
                            <TableHead className="text-right">
                                Cost each
                            </TableHead>
                            <TableHead className="text-right">Total</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {order.lines.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell>{line.medicine}</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {line.ordered}{' '}
                                    {line.pack ? `× ${line.pack}` : line.unit}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {line.received}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(line.unitCostMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(line.ordered * line.unitCostMinor)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell
                                colSpan={4}
                                className="text-right font-medium"
                            >
                                Total
                            </TableCell>
                            <TableCell className="text-right font-medium tabular-nums">
                                {money(order.totalMinor)}
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </Panel>
        </>
    );
}
