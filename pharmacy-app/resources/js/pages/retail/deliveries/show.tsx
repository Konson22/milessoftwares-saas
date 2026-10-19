import { Head, Link, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { formatMoney } from '@/lib/money';
import { show as showOrder } from '@/routes/retail/orders';
import { show as showSale } from '@/routes/retail/sales';

type Props = {
    delivery: {
        id: string;
        number: string;
        deliveredAt: string;
        deliveredBy: string;
        receivedByName: string | null;
        note: string | null;
        order: {
            id: string;
            reference: string;
            customerReference: string | null;
        };
        customer: {
            id: string;
            name: string;
            number: string;
            address: string | null;
            phone: string | null;
        };
        invoice: {
            id: string;
            number: string;
            totalMinor: number;
            dueOn: string | null;
        };
        lines: {
            id: string;
            item: string;
            unit: string;
            pack: string | null;
            quantity: number;
        }[];
    };
};

/**
 * A delivery note to print and hand over with the goods, signed by whoever
 * receives them. Prices are on the invoice, not here.
 */
export default function ShowDelivery({ delivery }: Props) {
    const { currency, workspace, branch } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title={delivery.number} />
            <PageHeader
                title={`Delivery ${delivery.number}`}
                description={`Order ${delivery.order.reference} · invoiced as ${delivery.invoice.number} (${money(delivery.invoice.totalMinor)}${delivery.invoice.dueOn ? `, due ${delivery.invoice.dueOn}` : ''})`}
                actions={
                    <div className="flex flex-wrap gap-2 print:hidden">
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={showOrder(delivery.order.id)}>
                                ← Order {delivery.order.reference}
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={showSale(delivery.invoice.id)}>
                                Invoice {delivery.invoice.number}
                            </Link>
                        </Button>
                        <Button size="sm" onClick={() => window.print()}>
                            <Printer className="size-4" aria-hidden />
                            Print delivery note
                        </Button>
                    </div>
                }
            />

            <Panel className="mx-auto max-w-2xl print:border-0 print:shadow-none">
                <PanelBody className="grid gap-4 p-6 text-sm">
                    <header className="flex flex-wrap justify-between gap-4">
                        <div>
                            <p className="font-semibold">{workspace?.name}</p>
                            <p className="text-muted-foreground">
                                {branch?.name}
                            </p>
                        </div>
                        <div className="text-right">
                            <p className="text-lg font-semibold">
                                Delivery note {delivery.number}
                            </p>
                            <p className="text-muted-foreground">
                                {new Date(
                                    delivery.deliveredAt,
                                ).toLocaleString()}
                            </p>
                        </div>
                    </header>

                    <div className="grid gap-1 sm:grid-cols-2">
                        <div>
                            <p className="text-muted-foreground">Deliver to</p>
                            <p className="font-medium">
                                {delivery.customer.name} (
                                {delivery.customer.number})
                            </p>
                            {delivery.customer.address && (
                                <p>{delivery.customer.address}</p>
                            )}
                            {delivery.customer.phone && (
                                <p>{delivery.customer.phone}</p>
                            )}
                        </div>
                        <div className="sm:text-right">
                            <p>
                                <span className="text-muted-foreground">
                                    Our order{' '}
                                </span>
                                {delivery.order.reference}
                            </p>
                            {delivery.order.customerReference && (
                                <p>
                                    <span className="text-muted-foreground">
                                        Your order{' '}
                                    </span>
                                    {delivery.order.customerReference}
                                </p>
                            )}
                            <p>
                                <span className="text-muted-foreground">
                                    Invoice{' '}
                                </span>
                                {delivery.invoice.number}
                            </p>
                        </div>
                    </div>

                    <table className="w-full">
                        <thead>
                            <tr className="border-b text-left text-muted-foreground">
                                <th className="py-1.5 font-normal">Product</th>
                                <th className="py-1.5 text-right font-normal">
                                    Quantity
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {delivery.lines.map((line) => (
                                <tr key={line.id}>
                                    <td className="py-1.5">{line.item}</td>
                                    <td className="py-1.5 text-right tabular-nums">
                                        {line.quantity}{' '}
                                        {line.pack
                                            ? `× ${line.pack}`
                                            : line.unit}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {delivery.note && <p>{delivery.note}</p>}

                    <div className="mt-6 grid gap-6 sm:grid-cols-2">
                        <div>
                            <p className="text-muted-foreground">
                                Delivered by
                            </p>
                            <p>{delivery.deliveredBy}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground">
                                Received in good order by
                            </p>
                            <p className="mt-6 border-t pt-1">
                                {delivery.receivedByName ??
                                    'Name and signature'}
                            </p>
                        </div>
                    </div>
                </PanelBody>
            </Panel>
        </>
    );
}
