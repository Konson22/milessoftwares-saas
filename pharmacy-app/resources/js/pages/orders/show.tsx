import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Check, Printer } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Barcode } from '@/components/barcode';
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import {
    cancel,
    handOver as handOverRoute,
    index,
    pay,
    print,
} from '@/routes/orders';
import { receipt, show as showSale } from '@/routes/sales';
import { store as openTill } from '@/routes/tills';

type Props = {
    order: {
        id: string;
        number: string;
        status: string;
        workflow: string;
        handOver: 'dispensing' | 'collection';
        paysFirst: boolean;
        customerName: string | null;
        customerPhone: string | null;
        customer: { id: string; number: string; name: string } | null;
        totalMinor: number;
        orderedAt: string;
        orderedBy: string;
        paidAt: string | null;
        sale: { id: string; number: string; status: string } | null;
        handedOverAt: string | null;
        handedOverBy: string | null;
        cancelledAt: string | null;
        cancelledBy: string | null;
        cancelReason: string | null;
        lines: {
            id: string;
            medicine: string;
            pack: string | null;
            quantity: number;
            discountPercent: number;
            lineTotalMinor: number;
            /** Dispensed before payment: the batches it came from. */
            batches: {
                batchNumber: string;
                expiresOn: string | null;
                quantity: number;
            }[];
        }[];
        awaitsPayment: boolean;
        awaitsHandOver: boolean;
    };
    can: { pay: boolean; handOver: boolean; cancel: boolean };
    till: { id: string; number: string } | null;
    /** Whether money is taken only in an open shift; its usual starting cash. */
    shifts: { required: boolean; defaultFloat: string };
    paymentMethods: { value: string; label: string }[];
    /** Times each document was printed. */
    prints: Record<'order_slip' | 'collection_slip' | 'receipt', number>;
};

const STATUS_LABELS: Record<string, string> = {
    open: 'Open',
    paid: 'Paid',
    handed_over: 'Dispensed',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

/**
 * One counter order: its number to call out or scan (shown large, so it
 * works with printing off), its steps, and the step this counter can take.
 */
export default function Order({
    order,
    can,
    till,
    shifts,
    paymentMethods,
    prints,
}: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const decimals = money(0).includes('.') ? 2 : 0;
    const [method, setMethod] = useState(paymentMethods[0]?.value ?? 'cash');
    const [tendered, setTendered] = useState('');
    const handOverVerb =
        order.handOver === 'collection' ? 'Collected' : 'Dispensed';
    const change =
        method === 'cash' && tendered !== ''
            ? Math.round(Number.parseFloat(tendered) * 10 ** decimals) -
              order.totalMinor
            : null;

    const steps = [
        { label: 'Ordered', at: order.orderedAt, by: order.orderedBy },
        ...(order.paysFirst
            ? [
                  { label: 'Paid', at: order.paidAt, by: null },
                  {
                      label: handOverVerb,
                      at: order.handedOverAt,
                      by: order.handedOverBy,
                  },
              ]
            : [
                  {
                      label: handOverVerb,
                      at: order.handedOverAt,
                      by: order.handedOverBy,
                  },
                  { label: 'Paid', at: order.paidAt, by: null },
              ]),
    ];

    return (
        <>
            <Head title={`Order ${order.number}`} />
            <PageHeader
                title={`Order ${order.number}`}
                description={order.workflow}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>Order queue</Link>
                    </Button>
                }
            />

            <div className="grid gap-3 lg:grid-cols-[1fr_22rem]">
                <div className="grid content-start gap-3">
                    <Panel>
                        <PanelBody className="flex flex-wrap items-center gap-6">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Order number
                                </p>
                                <p className="text-4xl font-bold tracking-wider tabular-nums">
                                    {order.number}
                                </p>
                                <StatusBadge
                                    tone={statusTone(order.status)}
                                    className="mt-1"
                                >
                                    {STATUS_LABELS[order.status] ??
                                        order.status}
                                </StatusBadge>
                            </div>
                            <Barcode
                                value={order.number}
                                className="w-56 font-mono text-xs"
                            />
                            <ol className="ml-auto grid gap-1 text-sm">
                                {steps.map((step) => (
                                    <li
                                        key={step.label}
                                        className="flex items-center gap-2"
                                    >
                                        <Check
                                            className={
                                                step.at
                                                    ? 'size-4 text-emerald-600'
                                                    : 'size-4 text-muted-foreground/30'
                                            }
                                            aria-hidden
                                        />
                                        <span
                                            className={
                                                step.at
                                                    ? ''
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {step.label}
                                            {step.at &&
                                                ` ${new Date(step.at).toLocaleTimeString()}`}
                                            {step.by && ` · ${step.by}`}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </PanelBody>
                    </Panel>

                    <Panel>
                        <PanelHeader
                            title="Items"
                            description={
                                <>
                                    {order.customer ? (
                                        <>
                                            {order.customer.name} (
                                            {order.customer.number})
                                        </>
                                    ) : (
                                        (order.customerName ??
                                        'Walk-in Customer')
                                    )}
                                    {order.customerPhone &&
                                        ` · ${order.customerPhone}`}
                                </>
                            }
                        />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Medicine</TableHead>
                                    <TableHead>Quantity</TableHead>
                                    <TableHead className="text-right">
                                        Total
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {order.lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell>
                                            <span className="font-medium">
                                                {line.medicine}
                                            </span>
                                            {line.batches.length > 0 && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {line.batches
                                                        .map(
                                                            (batch) =>
                                                                `${batch.quantity} from ${batch.batchNumber}${batch.expiresOn ? ` (exp. ${batch.expiresOn})` : ''}`,
                                                        )
                                                        .join(', ')}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {line.quantity}
                                            {line.pack && ` × ${line.pack}`}
                                            {line.discountPercent > 0 &&
                                                ` · ${line.discountPercent}% off`}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(line.lineTotalMinor)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                <TableRow>
                                    <TableCell
                                        colSpan={2}
                                        className="font-semibold"
                                    >
                                        Total
                                    </TableCell>
                                    <TableCell className="text-right font-semibold tabular-nums">
                                        {money(order.totalMinor)}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </Panel>

                    {order.cancelledAt && (
                        <Panel>
                            <PanelBody className="text-sm">
                                Cancelled{' '}
                                {new Date(order.cancelledAt).toLocaleString()}
                                {order.cancelledBy &&
                                    ` by ${order.cancelledBy}`}
                                : {order.cancelReason}
                            </PanelBody>
                        </Panel>
                    )}
                </div>

                <div className="grid content-start gap-3">
                    {can.pay &&
                        (till === null && shifts.required ? (
                            <Panel>
                                <PanelHeader title="Take payment" />
                                <PanelBody>
                                    <Form
                                        {...openTill.form()}
                                        className="flex flex-wrap items-end gap-2"
                                    >
                                        {({ processing }) => (
                                            <>
                                                <div className="grid gap-1.5">
                                                    <Label htmlFor="opening_float">
                                                        Start your shift:
                                                        starting cash
                                                    </Label>
                                                    <Input
                                                        id="opening_float"
                                                        name="opening_float"
                                                        inputMode="decimal"
                                                        defaultValue={
                                                            shifts.defaultFloat
                                                        }
                                                        className="w-40"
                                                    />
                                                </div>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Start shift
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </PanelBody>
                            </Panel>
                        ) : (
                            <Form
                                {...pay.form(order.id)}
                                className="grid content-start gap-3 rounded-lg border bg-card p-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <p className="flex items-baseline justify-between">
                                            <span className="text-muted-foreground">
                                                To pay
                                            </span>
                                            <span className="text-2xl font-semibold tabular-nums">
                                                {money(order.totalMinor)}
                                            </span>
                                        </p>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="payment_method">
                                                Payment
                                            </Label>
                                            <select
                                                id="payment_method"
                                                name="payment_method"
                                                value={method}
                                                onChange={(event) =>
                                                    setMethod(
                                                        event.target.value,
                                                    )
                                                }
                                                className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                            >
                                                {paymentMethods.map(
                                                    (option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>
                                        {method === 'cash' ? (
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="amount_tendered">
                                                    Cash given
                                                </Label>
                                                <Input
                                                    id="amount_tendered"
                                                    name="amount_tendered"
                                                    inputMode="decimal"
                                                    value={tendered}
                                                    onChange={(event) =>
                                                        setTendered(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                {change !== null && (
                                                    <p
                                                        className={
                                                            change < 0
                                                                ? 'text-sm text-destructive'
                                                                : 'text-sm'
                                                        }
                                                    >
                                                        {change < 0
                                                            ? `Short by ${money(-change)}`
                                                            : `Change: ${money(change)}`}
                                                    </p>
                                                )}
                                                {errors.amount_tendered && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.amount_tendered}
                                                    </p>
                                                )}
                                            </div>
                                        ) : (
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="payment_reference">
                                                    Payment reference
                                                </Label>
                                                <Input
                                                    id="payment_reference"
                                                    name="payment_reference"
                                                />
                                            </div>
                                        )}
                                        {order.customer === null &&
                                            order.customerName === null && (
                                                <div className="grid gap-1.5">
                                                    <Label htmlFor="customer_name">
                                                        Customer name (optional)
                                                    </Label>
                                                    <Input
                                                        id="customer_name"
                                                        name="customer_name"
                                                        autoComplete="off"
                                                    />
                                                    {errors.customer_name && (
                                                        <p className="text-sm text-destructive">
                                                            {
                                                                errors.customer_name
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                        {errors.payment_method && (
                                            <p className="text-sm text-destructive">
                                                {errors.payment_method}
                                            </p>
                                        )}
                                        <Button
                                            type="submit"
                                            size="lg"
                                            disabled={processing}
                                        >
                                            Take payment
                                        </Button>
                                    </>
                                )}
                            </Form>
                        ))}

                    {can.handOver && (
                        <Form
                            {...handOverRoute.form(order.id)}
                            className="grid gap-2 rounded-lg border bg-card p-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <p className="text-sm text-muted-foreground">
                                        {order.handOver === 'collection'
                                            ? 'Hand the goods to the customer against their slip.'
                                            : order.paysFirst
                                              ? 'Paid: dispense and hand over.'
                                              : 'Dispense now; the customer pays at the cashier.'}
                                    </p>
                                    {errors.order && (
                                        <p className="text-sm text-destructive">
                                            {errors.order}
                                        </p>
                                    )}
                                    <Button
                                        type="submit"
                                        size="lg"
                                        disabled={processing}
                                    >
                                        {order.handOver === 'collection'
                                            ? 'Mark collected'
                                            : 'Mark dispensed'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}

                    {order.sale && (
                        <Panel>
                            <PanelBody className="text-sm">
                                Sale{' '}
                                <Link
                                    href={showSale(order.sale.id)}
                                    className="font-medium hover:underline"
                                >
                                    {order.sale.number}
                                </Link>
                            </PanelBody>
                        </Panel>
                    )}

                    <Panel>
                        <PanelHeader title="Print" />
                        <PanelBody className="grid gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={print.url({
                                        order: order.id,
                                        document: 'order_slip',
                                    })}
                                >
                                    <Printer className="size-4" aria-hidden />
                                    Order slip
                                    {prints.order_slip > 0 &&
                                        ` (printed ${prints.order_slip}×)`}
                                </Link>
                            </Button>
                            {order.sale && order.handOver === 'collection' && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={print.url({
                                            order: order.id,
                                            document: 'collection_slip',
                                        })}
                                    >
                                        <Printer
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Collection slip
                                        {prints.collection_slip > 0 &&
                                            ` (printed ${prints.collection_slip}×)`}
                                    </Link>
                                </Button>
                            )}
                            {order.sale && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={receipt(order.sale.id)}>
                                        <Printer
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Receipt
                                        {prints.receipt > 0 &&
                                            ` (printed ${prints.receipt}×)`}
                                    </Link>
                                </Button>
                            )}
                        </PanelBody>
                    </Panel>

                    {can.cancel && (
                        <Form
                            {...cancel.form(order.id)}
                            className="grid gap-2 rounded-lg border bg-card p-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Label htmlFor="reason">
                                        Cancel the order
                                    </Label>
                                    <Input
                                        id="reason"
                                        name="reason"
                                        placeholder="Why"
                                        required
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
                                        Cancel order
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            </div>
        </>
    );
}
