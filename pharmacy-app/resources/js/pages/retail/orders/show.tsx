import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
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
    TableEmpty,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import { uuid } from '@/lib/uuid';
import { show as showCustomer } from '@/routes/retail/customers';
import { show as showDelivery } from '@/routes/retail/deliveries';
import { cancel, close, confirm, index } from '@/routes/retail/orders';
import { store as deliver } from '@/routes/retail/orders/deliveries';
import { show as showSale } from '@/routes/retail/sales';

type Props = {
    order: {
        id: string;
        reference: string;
        quoteNumber: string;
        orderNumber: string | null;
        status: string;
        statusLabel: string;
        expired: boolean;
        validUntil: string;
        customerReference: string | null;
        notes: string | null;
        priceList: string | null;
        totalMinor: number;
        createdAt: string;
        createdBy: string;
        confirmedAt: string | null;
        lines: {
            id: string;
            item: string;
            unit: string;
            pack: string | null;
            quantity: number;
            delivered: number;
            remaining: number;
            available: number;
            unitPriceMinor: number;
            discountPercent: number;
            lineTotalMinor: number;
        }[];
        deliveries: {
            id: string;
            number: string;
            deliveredAt: string;
            deliveredBy: string;
            saleId: string;
            invoiceNumber: string;
            totalMinor: number;
        }[];
    };
    customer: {
        id: string;
        number: string;
        name: string;
        owedMinor: number;
        availableMinor: number;
    };
    canManage: boolean;
    canDeliver: boolean;
};

/**
 * A quotation or sales order: its lines at the quoted prices, what was
 * delivered on it, and the delivery of what is left. Prints as the
 * quotation to send to the customer.
 */
export default function ShowOrder({
    order,
    customer,
    canManage,
    canDeliver,
}: Props) {
    const { currency, errors, workspace } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const [submissionId, setSubmissionId] = useState(() => uuid());
    const isQuote = order.status === 'quote';
    const toDeliver = order.lines.filter((line) => line.remaining > 0);

    return (
        <>
            <Head title={order.reference} />
            <PageHeader
                title={
                    isQuote
                        ? `Quotation ${order.quoteNumber}`
                        : `Order ${order.reference}`
                }
                description={[
                    order.orderNumber ? `Quotation ${order.quoteNumber}` : null,
                    order.customerReference
                        ? `Their order ${order.customerReference}`
                        : null,
                    order.priceList ? `Prices: ${order.priceList}` : null,
                    `By ${order.createdBy}`,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                badges={
                    <StatusBadge
                        tone={
                            order.expired ? 'danger' : statusTone(order.status)
                        }
                    >
                        {order.expired ? 'Expired' : order.statusLabel}
                    </StatusBadge>
                }
                actions={
                    <div className="flex flex-wrap gap-2 print:hidden">
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={index()}>← Orders</Link>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => window.print()}
                        >
                            <Printer className="size-4" aria-hidden />
                            Print
                        </Button>
                        {canManage && isQuote && !order.expired && (
                            <Form {...confirm.form(order.id)}>
                                <Button type="submit" size="sm">
                                    Customer accepted: confirm order
                                </Button>
                            </Form>
                        )}
                        {canManage &&
                            ['quote', 'confirmed'].includes(order.status) && (
                                <Form {...cancel.form(order.id)}>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                    >
                                        Cancel
                                    </Button>
                                </Form>
                            )}
                        {canManage && order.status === 'partly_delivered' && (
                            <Form {...close.form(order.id)}>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                >
                                    Close short
                                </Button>
                            </Form>
                        )}
                    </div>
                }
            />
            {errors.status && (
                <p className="mb-3 text-sm text-destructive">{errors.status}</p>
            )}

            <div className="mb-3 hidden text-sm print:block">
                <p className="font-semibold">{workspace?.name}</p>
                <p>
                    To {customer.name} ({customer.number}) ·{' '}
                    {isQuote
                        ? `valid until ${order.validUntil}`
                        : `confirmed ${order.confirmedAt ? new Date(order.confirmedAt).toLocaleDateString() : ''}`}
                </p>
            </div>

            <Panel className="mb-3 print:hidden">
                <PanelBody className="flex flex-wrap gap-x-8 gap-y-2 text-sm">
                    <p>
                        <span className="text-muted-foreground">Customer </span>
                        <Link
                            href={showCustomer(customer.id)}
                            className="font-medium hover:underline"
                        >
                            {customer.name}
                        </Link>{' '}
                        <span className="text-muted-foreground">
                            {customer.number}
                        </span>
                    </p>
                    <p>
                        <span className="text-muted-foreground">Owes </span>
                        <span className="tabular-nums">
                            {money(customer.owedMinor)}
                        </span>
                    </p>
                    <p>
                        <span className="text-muted-foreground">
                            Can still buy{' '}
                        </span>
                        <span className="tabular-nums">
                            {money(customer.availableMinor)}
                        </span>
                    </p>
                    {isQuote && (
                        <p>
                            <span className="text-muted-foreground">
                                Valid until{' '}
                            </span>
                            {order.validUntil}
                        </p>
                    )}
                    {order.notes && <p className="w-full">{order.notes}</p>}
                </PanelBody>
            </Panel>

            <Panel className="mb-3">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Product</TableHead>
                            <TableHead className="text-right">
                                Quantity
                            </TableHead>
                            {!isQuote && (
                                <TableHead className="text-right print:hidden">
                                    Delivered
                                </TableHead>
                            )}
                            <TableHead className="text-right">Price</TableHead>
                            <TableHead className="text-right">Total</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {order.lines.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell>{line.item}</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {line.quantity}{' '}
                                    {line.pack ? `× ${line.pack}` : line.unit}
                                </TableCell>
                                {!isQuote && (
                                    <TableCell className="text-right tabular-nums print:hidden">
                                        {line.delivered}
                                    </TableCell>
                                )}
                                <TableCell className="text-right tabular-nums">
                                    {money(line.unitPriceMinor)}
                                    {line.discountPercent > 0 && (
                                        <span className="block text-xs text-muted-foreground">
                                            less {line.discountPercent}%
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(line.lineTotalMinor)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell
                                colSpan={isQuote ? 3 : 4}
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

            {canDeliver && toDeliver.length > 0 && (
                <Panel className="mb-3 print:hidden">
                    <PanelHeader
                        title="Deliver"
                        description="Takes the goods out of stock and invoices them to the customer's account."
                    />
                    <Form
                        {...deliver.form(order.id)}
                        onSuccess={() => setSubmissionId(uuid())}
                    >
                        {({ errors: formErrors, processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="submission_id"
                                    value={submissionId}
                                />
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Product</TableHead>
                                            <TableHead className="text-right">
                                                Still to deliver
                                            </TableHead>
                                            <TableHead className="text-right">
                                                In stock
                                            </TableHead>
                                            <TableHead className="w-36 text-right">
                                                Delivering now
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {toDeliver.map((line) => (
                                            <TableRow key={line.id}>
                                                <TableCell>
                                                    {line.item}
                                                    {formErrors[
                                                        `lines.${line.id}`
                                                    ] && (
                                                        <p className="text-xs text-destructive">
                                                            {
                                                                formErrors[
                                                                    `lines.${line.id}`
                                                                ]
                                                            }
                                                        </p>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {line.remaining}{' '}
                                                    {line.pack
                                                        ? `× ${line.pack}`
                                                        : line.unit}
                                                </TableCell>
                                                <TableCell
                                                    className={`text-right tabular-nums ${line.available < line.remaining ? 'text-amber-600' : ''}`}
                                                >
                                                    {line.available}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Input
                                                        name={`lines[${line.id}]`}
                                                        type="number"
                                                        min={0}
                                                        max={line.remaining}
                                                        defaultValue={Math.min(
                                                            line.remaining,
                                                            line.available,
                                                        )}
                                                        aria-label={`Delivering now: ${line.item}`}
                                                        className="ml-auto h-8 w-28 text-right"
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                <PanelBody className="flex flex-wrap items-end gap-3">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="received_by_name">
                                            Received by
                                        </Label>
                                        <Input
                                            id="received_by_name"
                                            name="received_by_name"
                                            className="w-56"
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="note">Note</Label>
                                        <Input
                                            id="note"
                                            name="note"
                                            className="w-64"
                                        />
                                    </div>
                                    <Button type="submit" disabled={processing}>
                                        Deliver and invoice
                                    </Button>
                                    {formErrors.lines && (
                                        <p className="w-full text-sm text-destructive">
                                            {formErrors.lines}
                                        </p>
                                    )}
                                    {formErrors.customer_id && (
                                        <p className="w-full text-sm text-destructive">
                                            {formErrors.customer_id}
                                        </p>
                                    )}
                                </PanelBody>
                            </>
                        )}
                    </Form>
                </Panel>
            )}

            {!isQuote && (
                <Panel className="print:hidden">
                    <PanelHeader title="Deliveries" />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Delivery</TableHead>
                                <TableHead>When</TableHead>
                                <TableHead>Invoice</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {order.deliveries.length === 0 ? (
                                <TableEmpty colSpan={4}>
                                    Nothing delivered yet.
                                </TableEmpty>
                            ) : (
                                order.deliveries.map((delivery) => (
                                    <TableRow key={delivery.id}>
                                        <TableCell>
                                            <Link
                                                href={showDelivery(delivery.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {delivery.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {new Date(
                                                delivery.deliveredAt,
                                            ).toLocaleString()}{' '}
                                            <span className="text-muted-foreground">
                                                {delivery.deliveredBy}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                href={showSale(delivery.saleId)}
                                                className="hover:underline"
                                            >
                                                {delivery.invoiceNumber}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(delivery.totalMinor)}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Panel>
            )}
        </>
    );
}
