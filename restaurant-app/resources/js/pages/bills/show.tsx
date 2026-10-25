import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import { index, voidMethod as voidBill } from '@/routes/bills';
import { show as showOrder } from '@/routes/orders';

type Props = {
    bill: {
        id: string;
        number: string;
        status: string;
        paidAt: string;
        paidBy: string;
        order: { id: string; number: string; table: string | null };
        customerName: string | null;
        currency: string;
        totalMinor: number;
        paymentMethod: string;
        paymentReference: string | null;
        tenderedMinor: number | null;
        /** Null unless cash was counted out. */
        changeMinor: number | null;
        voidedAt: string | null;
        voidedBy: string | null;
        voidReason: string | null;
        lines: {
            id: string;
            name: string;
            quantity: number;
            unitPriceMinor: number;
            totalMinor: number;
        }[];
    };
    canVoid: boolean;
};

/**
 * A paid bill, printable as the receipt, and for managers voiding it.
 */
export default function ShowBill({ bill, canVoid }: Props) {
    const { workspace, branch } = usePage().props;
    const money = (minor: number) => formatMoney(minor, bill.currency);

    return (
        <>
            <Head title={bill.number} />
            <PageHeader
                title={`Bill ${bill.number}`}
                badges={
                    <StatusBadge tone={statusTone(bill.status)}>
                        {bill.status}
                    </StatusBadge>
                }
                actions={
                    <div className="flex flex-wrap gap-2 print:hidden">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>← Bills paid</Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={showOrder(bill.order.id)}>
                                Order {bill.order.number}
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => window.print()}
                        >
                            <Printer className="size-4" aria-hidden />
                            Print receipt
                        </Button>
                    </div>
                }
            />

            <Panel className="mx-auto max-w-md print:border-0 print:shadow-none">
                <PanelBody className="p-4">
                    <header className="text-center">
                        <p className="font-semibold">{workspace?.name}</p>
                        <p className="text-sm text-muted-foreground">
                            {branch?.name}
                        </p>
                        <h1 className="mt-3 text-xl font-semibold">
                            Receipt {bill.number}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {new Date(bill.paidAt).toLocaleString()} ·{' '}
                            {bill.paidBy}
                        </p>
                        {(bill.order.table || bill.customerName) && (
                            <p className="text-sm">
                                {bill.order.table
                                    ? `Table ${bill.order.table}`
                                    : bill.customerName}
                            </p>
                        )}
                    </header>

                    <table className="mt-4 w-full text-sm">
                        <tbody className="divide-y">
                            {bill.lines.map((line) => (
                                <tr key={line.id}>
                                    <td className="py-1.5">
                                        {line.name}
                                        <span className="block text-xs text-muted-foreground">
                                            {line.quantity} ×{' '}
                                            {money(line.unitPriceMinor)}
                                        </span>
                                    </td>
                                    <td className="py-1.5 text-right tabular-nums">
                                        {money(line.totalMinor)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t">
                            <tr className="font-semibold">
                                <td className="py-1.5">Total</td>
                                <td className="py-1.5 text-right tabular-nums">
                                    {money(bill.totalMinor)}
                                </td>
                            </tr>
                            <tr>
                                <td className="py-0.5 text-muted-foreground">
                                    {bill.paymentMethod}
                                    {bill.paymentReference &&
                                        ` (${bill.paymentReference})`}
                                </td>
                                <td className="py-0.5 text-right tabular-nums">
                                    {bill.tenderedMinor !== null
                                        ? money(bill.tenderedMinor)
                                        : ''}
                                </td>
                            </tr>
                            {bill.changeMinor !== null && (
                                <tr>
                                    <td className="py-0.5 text-muted-foreground">
                                        Change
                                    </td>
                                    <td className="py-0.5 text-right tabular-nums">
                                        {money(bill.changeMinor)}
                                    </td>
                                </tr>
                            )}
                        </tfoot>
                    </table>

                    {bill.status === 'voided' && (
                        <p className="mt-4 text-sm text-destructive">
                            Voided by {bill.voidedBy} on{' '}
                            {bill.voidedAt &&
                                new Date(bill.voidedAt).toLocaleString()}
                            : {bill.voidReason}
                        </p>
                    )}
                </PanelBody>
            </Panel>

            {canVoid && bill.status !== 'voided' && (
                <Form
                    {...voidBill.form(bill.id)}
                    className="mx-auto mt-4 flex max-w-md flex-wrap items-start gap-2 print:hidden"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="flex-1">
                                <Input
                                    name="reason"
                                    required
                                    placeholder="Why is this bill voided?"
                                    aria-label="Reason for voiding"
                                />
                                {errors.reason && (
                                    <p className="text-sm text-destructive">
                                        {errors.reason}
                                    </p>
                                )}
                            </div>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                Void bill and refund
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </>
    );
}
