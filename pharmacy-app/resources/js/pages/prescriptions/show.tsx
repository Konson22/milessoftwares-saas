import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { statusTone } from '@/lib/status';
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
import { formatMoney } from '@/lib/money';
import { index as tills } from '@/routes/tills';
import {
    cancel,
    dispense,
    index,
    reject,
    verify,
} from '@/routes/prescriptions';
import { show as showSale } from '@/routes/sales';

type Props = {
    prescription: {
        id: string;
        number: string;
        patientName: string;
        patientAge: number | null;
        prescriberName: string;
        prescriberRegistration: string | null;
        issuedOn: string;
        expiresOn: string;
        expired: boolean;
        status: string;
        statusLabel: string;
        notes: string | null;
        recordedBy: string;
        verifiedBy: string | null;
        verifiedAt: string | null;
        rejectionReason: string | null;
        sales: {
            id: string;
            number: string;
            soldAt: string;
            totalMinor: number;
            voided: boolean;
        }[];
        items: {
            id: string;
            medicine: string;
            controlled: boolean;
            unit: string;
            quantity: number;
            dispensed: number;
            remaining: number;
            unitPriceMinor: number;
            directions: string;
            available: number;
        }[];
    };
    can: {
        verify: boolean;
        reject: boolean;
        cancel: boolean;
        dispense: boolean;
    };
    paymentMethods: { value: string; label: string }[];
};

export default function ShowPrescription({
    prescription,
    can,
    paymentMethods,
}: Props) {
    const { currency, errors } = usePage().props;
    const [method, setMethod] = useState(paymentMethods[0]?.value ?? 'cash');
    const open = prescription.items.filter((item) => item.remaining > 0);
    // What is given now: by default all that is left, as far as stock allows.
    const [quantities, setQuantities] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            open.map((item) => [
                item.id,
                String(Math.min(item.remaining, item.available)),
            ]),
        ),
    );
    const giving = open.map((item) => ({
        item,
        quantity: Math.max(0, Math.floor(Number(quantities[item.id]) || 0)),
    }));
    const totalMinor = giving.reduce(
        (sum, { item, quantity }) => sum + quantity * item.unitPriceMinor,
        0,
    );
    const short = giving.some(
        ({ item, quantity }) => quantity > item.available,
    );
    const nothing = giving.every(({ quantity }) => quantity === 0);

    return (
        <>
            <Head title={prescription.number} />
            <PageHeader
                title={prescription.number}
                badges={
                    <>
                        <StatusBadge tone={statusTone(prescription.status)}>
                            {prescription.statusLabel}
                        </StatusBadge>
                        {prescription.expired && (
                            <StatusBadge tone="danger">Expired</StatusBadge>
                        )}
                    </>
                }
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Prescriptions</Link>
                    </Button>
                }
            />
            {errors.status && (
                <p className="mb-4 text-sm text-destructive">{errors.status}</p>
            )}
            {errors.till && (
                <p className="mb-4 text-sm text-destructive">
                    {errors.till}{' '}
                    <Link href={tills()} className="underline">
                        Open a till
                    </Link>
                </p>
            )}

            <Panel className="mb-3 max-w-3xl">
                <PanelBody>
                    <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]">
                        <dt className="text-muted-foreground">Patient</dt>
                        <dd>
                            {prescription.patientName}
                            {prescription.patientAge !== null &&
                                `, ${prescription.patientAge} years`}
                        </dd>
                        <dt className="text-muted-foreground">Prescriber</dt>
                        <dd>
                            {prescription.prescriberName}
                            {prescription.prescriberRegistration &&
                                ` (${prescription.prescriberRegistration})`}
                        </dd>
                        <dt className="text-muted-foreground">Written</dt>
                        <dd>
                            {prescription.issuedOn}, valid until{' '}
                            {prescription.expiresOn}
                        </dd>
                        <dt className="text-muted-foreground">Recorded by</dt>
                        <dd>{prescription.recordedBy}</dd>
                        {prescription.verifiedBy && (
                            <>
                                <dt className="text-muted-foreground">
                                    {prescription.status === 'rejected'
                                        ? 'Rejected by'
                                        : 'Verified by'}
                                </dt>
                                <dd>
                                    {prescription.verifiedBy}
                                    {prescription.verifiedAt &&
                                        `, ${new Date(prescription.verifiedAt).toLocaleString()}`}
                                    {prescription.rejectionReason &&
                                        `: ${prescription.rejectionReason}`}
                                </dd>
                            </>
                        )}
                        {prescription.sales.length > 0 && (
                            <>
                                <dt className="text-muted-foreground">
                                    Dispensed in
                                </dt>
                                <dd className="flex flex-wrap gap-x-3">
                                    {prescription.sales.map((sale) => (
                                        <span key={sale.id}>
                                            <Link
                                                href={showSale(sale.id)}
                                                className={
                                                    sale.voided
                                                        ? 'line-through'
                                                        : 'underline'
                                                }
                                            >
                                                {sale.number}
                                            </Link>{' '}
                                            <span className="text-muted-foreground">
                                                {new Date(
                                                    sale.soldAt,
                                                ).toLocaleDateString()}
                                                {sale.voided && ', voided'}
                                            </span>
                                        </span>
                                    ))}
                                </dd>
                            </>
                        )}
                        {prescription.notes && (
                            <>
                                <dt className="text-muted-foreground">Notes</dt>
                                <dd>{prescription.notes}</dd>
                            </>
                        )}
                    </dl>
                </PanelBody>
            </Panel>

            <Panel className="mb-3">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medicine</TableHead>
                            <TableHead>Quantity</TableHead>
                            <TableHead>Given</TableHead>
                            <TableHead>Directions</TableHead>
                            <TableHead className="text-right">
                                In stock
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {prescription.items.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell>
                                    <span className="font-medium">
                                        {item.medicine}
                                    </span>
                                    {item.controlled && (
                                        <StatusBadge
                                            tone="danger"
                                            className="ml-2"
                                        >
                                            Controlled
                                        </StatusBadge>
                                    )}
                                </TableCell>
                                <TableCell>
                                    {item.quantity} {item.unit}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {item.dispensed === 0 ? (
                                        <span className="text-muted-foreground">
                                            None yet
                                        </span>
                                    ) : item.remaining === 0 ? (
                                        <StatusBadge tone="success">
                                            All {item.dispensed}
                                        </StatusBadge>
                                    ) : (
                                        `${item.dispensed}, ${item.remaining} left`
                                    )}
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {item.directions}
                                </TableCell>
                                <TableCell
                                    className={`text-right tabular-nums ${item.available < item.remaining ? 'text-destructive' : 'text-muted-foreground'}`}
                                >
                                    {item.available}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>

            <div className="mb-3 flex flex-wrap gap-3">
                {can.verify && (
                    <Form {...verify.form(prescription.id)}>
                        <Button type="submit">Verify (pharmacist)</Button>
                    </Form>
                )}
                {can.cancel && (
                    <Form {...cancel.form(prescription.id)}>
                        <Button type="submit" variant="outline">
                            Cancel prescription
                        </Button>
                    </Form>
                )}
            </div>

            {can.reject && (
                <Form
                    {...reject.form(prescription.id)}
                    className="mb-3 flex max-w-xl flex-wrap items-start gap-2"
                >
                    {({ errors: formErrors, processing }) => (
                        <>
                            <div className="flex-1">
                                <Input
                                    name="reason"
                                    required
                                    placeholder="Why is it rejected?"
                                    aria-label="Reason for rejecting"
                                />
                                {formErrors.reason && (
                                    <p className="text-sm text-destructive">
                                        {formErrors.reason}
                                    </p>
                                )}
                            </div>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                Reject
                            </Button>
                        </>
                    )}
                </Form>
            )}

            {can.dispense && (
                <Panel className="max-w-xl">
                    <PanelHeader
                        title={`Dispense: ${formatMoney(totalMinor, currency ?? 'USD')}`}
                        description="Give all that is left, or part now and the rest on another visit."
                    />
                    <PanelBody>
                        <Form
                            {...dispense.form(prescription.id)}
                            className="grid gap-3"
                        >
                            {({ processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        {open.map((item) => (
                                            <div
                                                key={item.id}
                                                className="flex items-center gap-3"
                                            >
                                                <Label
                                                    htmlFor={`quantity-${item.id}`}
                                                    className="flex-1 font-normal"
                                                >
                                                    {item.medicine}
                                                    <span className="block text-xs text-muted-foreground">
                                                        {item.remaining} left,{' '}
                                                        {item.available} in
                                                        stock
                                                    </span>
                                                </Label>
                                                <Input
                                                    id={`quantity-${item.id}`}
                                                    name={`quantities[${item.id}]`}
                                                    type="number"
                                                    min={0}
                                                    max={item.remaining}
                                                    value={
                                                        quantities[item.id] ??
                                                        '0'
                                                    }
                                                    onChange={(event) =>
                                                        setQuantities(
                                                            (current) => ({
                                                                ...current,
                                                                [item.id]:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                    className="w-24 text-right tabular-nums"
                                                />
                                                <span className="w-12 text-sm text-muted-foreground">
                                                    {item.unit}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                    {(errors.quantities ||
                                        Object.entries(errors).some(([key]) =>
                                            key.startsWith('quantities.'),
                                        )) && (
                                        <p className="text-sm text-destructive">
                                            {errors.quantities ??
                                                Object.entries(errors).find(
                                                    ([key]) =>
                                                        key.startsWith(
                                                            'quantities.',
                                                        ),
                                                )?.[1]}
                                        </p>
                                    )}
                                    {short && (
                                        <p className="text-sm text-destructive">
                                            Not enough stock in this branch for
                                            what is entered.
                                        </p>
                                    )}
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="payment_method">
                                            Payment
                                        </Label>
                                        <select
                                            id="payment_method"
                                            name="payment_method"
                                            value={method}
                                            onChange={(event) =>
                                                setMethod(event.target.value)
                                            }
                                            className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                        >
                                            {paymentMethods.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
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
                                                required
                                            />
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
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || short || nothing
                                        }
                                    >
                                        Dispense and take payment
                                    </Button>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
