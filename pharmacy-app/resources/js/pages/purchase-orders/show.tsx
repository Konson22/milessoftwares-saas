import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
            <Link
                href={index()}
                className="text-sm text-muted-foreground hover:underline"
            >
                ← Purchase orders
            </Link>
            <div className="mt-2 flex flex-wrap items-center gap-3">
                <h1 className="text-2xl font-semibold">{order.number}</h1>
                <Badge variant="secondary">
                    {ORDER_STATUS[order.status] ?? order.status}
                </Badge>
                <div className="ml-auto flex gap-2">
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
                                href={receive({ query: { order: order.id } })}
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
            </div>
            {errors.status && (
                <p className="mt-2 text-sm text-destructive">{errors.status}</p>
            )}
            <p className="mt-1 text-muted-foreground">
                {order.supplier}
                {order.expectedOn && ` · expected ${order.expectedOn}`}
                {order.notes && ` · ${order.notes}`}
            </p>

            <div className="mt-6 overflow-x-auto rounded-md border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-4 py-2 font-medium">Medicine</th>
                            <th className="px-4 py-2 text-right font-medium">
                                Ordered
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Received
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Unit cost
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Total
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {order.lines.map((line) => (
                            <tr key={line.id}>
                                <td className="px-4 py-2">{line.medicine}</td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {line.ordered} {line.unit}
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {line.received}
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {money(line.unitCostMinor)}
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {money(line.ordered * line.unitCostMinor)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t font-medium">
                            <td colSpan={4} className="px-4 py-2 text-right">
                                Total
                            </td>
                            <td className="px-4 py-2 text-right tabular-nums">
                                {money(order.totalMinor)}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </>
    );
}
