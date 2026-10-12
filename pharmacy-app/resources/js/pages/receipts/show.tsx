import { Head, Link, usePage } from '@inertiajs/react';
import { formatMoney } from '@/lib/money';
import { show as showOrder } from '@/routes/purchase-orders';
import { index } from '@/routes/receipts';

type Props = {
    receipt: {
        id: string;
        number: string;
        supplier: string;
        order: { id: string; number: string } | null;
        supplierReference: string | null;
        receivedOn: string;
        receivedBy: string;
        totalMinor: number;
        lines: {
            id: string;
            medicine: string;
            batchNumber: string;
            expiresOn: string;
            quantity: number;
            unitCostMinor: number;
            lineTotalMinor: number;
        }[];
    };
};

export default function ShowReceipt({ receipt }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title={receipt.number} />
            <Link
                href={index()}
                className="text-sm text-muted-foreground hover:underline"
            >
                ← Goods received
            </Link>
            <h1 className="mt-2 text-2xl font-semibold">{receipt.number}</h1>
            <p className="mt-1 text-muted-foreground">
                {receipt.supplier} · received {receipt.receivedOn} by{' '}
                {receipt.receivedBy}
                {receipt.supplierReference && ` · ${receipt.supplierReference}`}
                {receipt.order && (
                    <>
                        {' · '}
                        <Link
                            href={showOrder(receipt.order.id)}
                            className="underline"
                        >
                            {receipt.order.number}
                        </Link>
                    </>
                )}
            </p>

            <div className="mt-6 overflow-x-auto rounded-md border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-4 py-2 font-medium">Medicine</th>
                            <th className="px-4 py-2 font-medium">Batch</th>
                            <th className="px-4 py-2 font-medium">Expires</th>
                            <th className="px-4 py-2 text-right font-medium">
                                Quantity
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
                        {receipt.lines.map((line) => (
                            <tr key={line.id}>
                                <td className="px-4 py-2">{line.medicine}</td>
                                <td className="px-4 py-2">
                                    {line.batchNumber}
                                </td>
                                <td className="px-4 py-2 tabular-nums">
                                    {line.expiresOn}
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {line.quantity}
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {money(line.unitCostMinor)}
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {money(line.lineTotalMinor)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t font-medium">
                            <td colSpan={5} className="px-4 py-2 text-right">
                                Owed to supplier
                            </td>
                            <td className="px-4 py-2 text-right tabular-nums">
                                {money(receipt.totalMinor)}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </>
    );
}
