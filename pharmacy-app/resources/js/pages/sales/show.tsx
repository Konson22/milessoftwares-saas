import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody } from '@/components/panel';
import { StatusBadge, statusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/money';
import { index, voidMethod as voidSale } from '@/routes/sales';

type Props = {
    sale: {
        id: string;
        number: string;
        status: string;
        soldAt: string;
        soldBy: string;
        customerName: string | null;
        currency: string;
        totalMinor: number;
        paymentMethod: string;
        paymentReference: string | null;
        tenderedMinor: number | null;
        changeMinor: number | null;
        voidedAt: string | null;
        voidedBy: string | null;
        voidReason: string | null;
        lines: {
            id: string;
            medicine: string;
            batchNumber: string;
            expiresOn: string;
            quantity: number;
            unitPriceMinor: number;
            lineTotalMinor: number;
        }[];
    };
    canVoid: boolean;
};

/**
 * The receipt (printable) and, for managers, voiding.
 */
export default function ShowSale({ sale, canVoid }: Props) {
    const { workspace, branch } = usePage().props;
    const money = (minor: number) => formatMoney(minor, sale.currency);

    return (
        <>
            <Head title={sale.number} />
            <PageHeader
                title={`Receipt ${sale.number}`}
                badges={
                    <StatusBadge tone={statusTone(sale.status)}>
                        {sale.status}
                    </StatusBadge>
                }
                actions={
                    <div className="flex flex-wrap gap-2 print:hidden">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>← Sales</Link>
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
                <PanelBody className="p-6">
                    <header className="text-center">
                        <p className="font-semibold">{workspace?.name}</p>
                        <p className="text-sm text-muted-foreground">
                            {branch?.name}
                        </p>
                        <h1 className="mt-3 text-xl font-semibold">
                            Receipt {sale.number}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {new Date(sale.soldAt).toLocaleString()} ·{' '}
                            {sale.soldBy}
                        </p>
                        {sale.customerName && (
                            <p className="text-sm">{sale.customerName}</p>
                        )}
                    </header>

                    <table className="mt-4 w-full text-sm">
                        <tbody className="divide-y">
                            {sale.lines.map((line) => (
                                <tr key={line.id}>
                                    <td className="py-1.5">
                                        {line.medicine}
                                        <span className="block text-xs text-muted-foreground">
                                            {line.quantity} ×{' '}
                                            {money(line.unitPriceMinor)} · batch{' '}
                                            {line.batchNumber}, exp{' '}
                                            {line.expiresOn}
                                        </span>
                                    </td>
                                    <td className="py-1.5 text-right tabular-nums">
                                        {money(line.lineTotalMinor)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t">
                            <tr className="font-semibold">
                                <td className="py-1.5">Total</td>
                                <td className="py-1.5 text-right tabular-nums">
                                    {money(sale.totalMinor)}
                                </td>
                            </tr>
                            <tr>
                                <td className="py-0.5 text-muted-foreground">
                                    {sale.paymentMethod}
                                    {sale.paymentReference &&
                                        ` (${sale.paymentReference})`}
                                </td>
                                <td className="py-0.5 text-right tabular-nums">
                                    {sale.tenderedMinor !== null
                                        ? money(sale.tenderedMinor)
                                        : ''}
                                </td>
                            </tr>
                            {sale.changeMinor !== null && (
                                <tr>
                                    <td className="py-0.5 text-muted-foreground">
                                        Change
                                    </td>
                                    <td className="py-0.5 text-right tabular-nums">
                                        {money(sale.changeMinor)}
                                    </td>
                                </tr>
                            )}
                        </tfoot>
                    </table>

                    {sale.status === 'voided' && (
                        <p className="mt-4 text-sm text-destructive">
                            Voided by {sale.voidedBy} on{' '}
                            {sale.voidedAt &&
                                new Date(sale.voidedAt).toLocaleString()}
                            : {sale.voidReason}
                        </p>
                    )}
                </PanelBody>
            </Panel>

            {canVoid && (
                <Form
                    {...voidSale.form(sale.id)}
                    className="mx-auto mt-6 flex max-w-md flex-wrap items-start gap-2 print:hidden"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="flex-1">
                                <Input
                                    name="reason"
                                    required
                                    placeholder="Why is this sale voided?"
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
                                Void sale and return stock
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </>
    );
}
