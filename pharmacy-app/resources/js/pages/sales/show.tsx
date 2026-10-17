import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { statusTone } from '@/lib/status';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { formatMoney } from '@/lib/money';
import { index, receipt, voidMethod as voidSale } from '@/routes/sales';
import { store as storeReturn } from '@/routes/sales/returns';

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
        discountMinor: number;
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
            discountMinor: number;
            lineTotalMinor: number;
            returned: number;
        }[];
        returns: {
            id: string;
            number: string;
            returnedAt: string;
            returnedBy: string;
            reason: string;
            totalMinor: number;
            lines: {
                id: string;
                medicine: string;
                quantity: number;
                amountMinor: number;
                restocked: boolean;
            }[];
        }[];
    };
    canVoid: boolean;
    canReturn: boolean;
};

/**
 * The sale (printable receipt), what was brought back, and for managers
 * returns and voiding.
 */
export default function ShowSale({ sale, canVoid, canReturn }: Props) {
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
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={receipt.url(sale.id)}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Printer className="size-4" aria-hidden />
                                Print receipt
                            </a>
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
                                            {line.discountMinor > 0 &&
                                                ` · less ${money(line.discountMinor)}`}
                                        </span>
                                    </td>
                                    <td className="py-1.5 text-right tabular-nums">
                                        {money(line.lineTotalMinor)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t">
                            {sale.discountMinor > 0 && (
                                <tr>
                                    <td className="py-0.5 text-muted-foreground">
                                        Discount
                                    </td>
                                    <td className="py-0.5 text-right tabular-nums">
                                        −{money(sale.discountMinor)}
                                    </td>
                                </tr>
                            )}
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

            {sale.returns.length > 0 && (
                <Panel className="mx-auto mt-6 max-w-md">
                    <PanelHeader title="Returned" />
                    <PanelBody>
                        <ul className="grid gap-3 text-sm">
                            {sale.returns.map((ret) => (
                                <li key={ret.id}>
                                    <p className="flex justify-between gap-2 font-medium">
                                        <span>{ret.number}</span>
                                        <span className="tabular-nums">
                                            −{money(ret.totalMinor)}
                                        </span>
                                    </p>
                                    <p className="text-muted-foreground">
                                        {new Date(
                                            ret.returnedAt,
                                        ).toLocaleString()}{' '}
                                        · {ret.returnedBy} · {ret.reason}
                                    </p>
                                    <ul className="mt-1">
                                        {ret.lines.map((line) => (
                                            <li key={line.id}>
                                                {line.quantity} ×{' '}
                                                {line.medicine}
                                                {line.restocked
                                                    ? ' (back in stock)'
                                                    : ' (not resold)'}
                                            </li>
                                        ))}
                                    </ul>
                                </li>
                            ))}
                        </ul>
                    </PanelBody>
                </Panel>
            )}

            {canReturn && (
                <Panel className="mx-auto mt-6 max-w-md print:hidden">
                    <PanelHeader title="Take a return" />
                    <PanelBody>
                        <Form
                            {...storeReturn.form(sale.id)}
                            className="grid gap-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    {sale.lines
                                        .filter(
                                            (line) =>
                                                line.quantity > line.returned,
                                        )
                                        .map((line, i) => (
                                            <div
                                                key={line.id}
                                                className="flex flex-wrap items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="hidden"
                                                    name={`lines[${i}][sale_line_id]`}
                                                    value={line.id}
                                                />
                                                <span className="min-w-40 flex-1">
                                                    {line.medicine}{' '}
                                                    <span className="text-muted-foreground">
                                                        (batch{' '}
                                                        {line.batchNumber}, up
                                                        to{' '}
                                                        {line.quantity -
                                                            line.returned}
                                                        )
                                                    </span>
                                                </span>
                                                <Input
                                                    type="number"
                                                    name={`lines[${i}][quantity]`}
                                                    min={0}
                                                    max={
                                                        line.quantity -
                                                        line.returned
                                                    }
                                                    defaultValue={0}
                                                    aria-label={`Quantity of ${line.medicine} returned`}
                                                    className="h-8 w-20"
                                                />
                                                <label className="flex items-center gap-1">
                                                    <input
                                                        type="checkbox"
                                                        name={`lines[${i}][restock]`}
                                                        value="1"
                                                        defaultChecked
                                                    />
                                                    Back in stock
                                                </label>
                                            </div>
                                        ))}
                                    {Object.entries(errors)
                                        .filter(([field]) =>
                                            field.startsWith('lines'),
                                        )
                                        .map(([field, message]) => (
                                            <p
                                                key={field}
                                                className="text-sm text-destructive"
                                            >
                                                {message}
                                            </p>
                                        ))}
                                    <Input
                                        name="reason"
                                        required
                                        placeholder="Why is it coming back?"
                                        aria-label="Reason for the return"
                                    />
                                    {errors.reason && (
                                        <p className="text-sm text-destructive">
                                            {errors.reason}
                                        </p>
                                    )}
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Return and refund ({sale.paymentMethod})
                                    </Button>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

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
