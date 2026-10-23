import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { statusTone } from '@/lib/status';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { formatMoney } from '@/lib/money';
import { index, receipt, voidMethod as voidSale } from '@/routes/retail/sales';
import { show as showCustomer } from '@/routes/retail/customers';
import { show as showDelivery } from '@/routes/retail/deliveries';
import { store as storeReturn } from '@/routes/retail/sales/returns';

type Props = {
    sale: {
        id: string;
        number: string;
        status: string;
        soldAt: string;
        soldBy: string;
        customerName: string | null;
        customer: { id: string; number: string; name: string } | null;
        onAccount: boolean;
        /** A wholesale invoice: the delivery note it was raised for. */
        delivery: { id: string; number: string } | null;
        dueOn: string | null;
        /** On account: what the customer still owes on this sale. */
        owedMinor: number | null;
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
            item: string;
            batchNumber: string;
            expiresOn: string | null;
            quantity: number;
            unitPriceMinor: number;
            discountMinor: number;
            lineTotalMinor: number;
            packName: string | null;
            unitsPerPack: number;
            packPriceMinor: number | null;
            returned: number;
            tracksSerials: boolean;
        }[];
        /** Serial numbers sold, not returned. */
        serials: string[];
        returns: {
            id: string;
            number: string;
            returnedAt: string;
            returnedBy: string;
            reason: string;
            totalMinor: number;
            lines: {
                id: string;
                item: string;
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
                <PanelBody className="p-4">
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
                        {sale.delivery && (
                            <p className="text-sm">
                                Invoice for delivery{' '}
                                <Link
                                    href={showDelivery(sale.delivery.id)}
                                    className="hover:underline print:no-underline"
                                >
                                    {sale.delivery.number}
                                </Link>
                            </p>
                        )}
                        {sale.customer ? (
                            <p className="text-sm">
                                <Link
                                    href={showCustomer(sale.customer.id)}
                                    className="hover:underline print:no-underline"
                                >
                                    {sale.customer.name}
                                </Link>{' '}
                                <span className="text-muted-foreground">
                                    {sale.customer.number}
                                </span>
                            </p>
                        ) : (
                            sale.customerName && (
                                <p className="text-sm">{sale.customerName}</p>
                            )
                        )}
                    </header>

                    <table className="mt-4 w-full text-sm">
                        <tbody className="divide-y">
                            {sale.lines.map((line) => (
                                <tr key={line.id}>
                                    <td className="py-1.5">
                                        {line.item}
                                        <span className="block text-xs text-muted-foreground">
                                            {line.packName
                                                ? `${line.quantity} from ${line.packName}s of ${line.unitsPerPack} at ${money(line.packPriceMinor ?? 0)}`
                                                : `${line.quantity} × ${money(line.unitPriceMinor)}`}{' '}
                                            · batch {line.batchNumber}, exp{' '}
                                            {line.expiresOn ?? '—'}
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
                            {sale.serials.length > 0 && (
                                <tr>
                                    <td
                                        colSpan={2}
                                        className="py-0.5 text-xs text-muted-foreground"
                                    >
                                        Serial numbers:{' '}
                                        <span className="font-mono">
                                            {sale.serials.join(', ')}
                                        </span>
                                    </td>
                                </tr>
                            )}
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
                            {sale.onAccount && sale.dueOn && (
                                <tr>
                                    <td className="py-0.5 text-muted-foreground">
                                        Due by {sale.dueOn}
                                    </td>
                                    <td className="py-0.5 text-right tabular-nums">
                                        {sale.owedMinor !== null &&
                                            `Owed ${money(sale.owedMinor)}`}
                                    </td>
                                </tr>
                            )}
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
                <Panel className="mx-auto mt-4 max-w-md">
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
                                                {line.quantity} × {line.item}
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
                <Panel className="mx-auto mt-4 max-w-md print:hidden">
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
                                                    {line.item}{' '}
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
                                                    aria-label={`Quantity of ${line.item} returned`}
                                                    className="h-8 w-20"
                                                />
                                                {line.tracksSerials && (
                                                    <Input
                                                        name={`lines[${i}][serials_text]`}
                                                        placeholder="Serial numbers back"
                                                        aria-label={`Serial numbers of ${line.item} returned`}
                                                        className="h-8 w-56 font-mono"
                                                    />
                                                )}
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
                                        {sale.onAccount
                                            ? 'Return and take off the account'
                                            : `Return and refund (${sale.paymentMethod})`}
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
                    className="mx-auto mt-4 flex max-w-md flex-wrap items-start gap-2 print:hidden"
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
