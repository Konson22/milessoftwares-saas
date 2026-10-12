import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/money';
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
        sale: { id: string; number: string } | null;
        totalMinor: number;
        items: {
            id: string;
            medicine: string;
            controlled: boolean;
            unit: string;
            quantity: number;
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
    const short = prescription.items.some(
        (item) => item.available < item.quantity,
    );

    return (
        <>
            <Head title={prescription.number} />
            <Link
                href={index()}
                className="text-sm text-muted-foreground hover:underline"
            >
                ← Prescriptions
            </Link>
            <div className="mt-2 flex flex-wrap items-center gap-3">
                <h1 className="text-2xl font-semibold">
                    {prescription.number}
                </h1>
                <Badge variant="secondary">{prescription.statusLabel}</Badge>
                {prescription.expired && (
                    <Badge variant="destructive">Expired</Badge>
                )}
            </div>
            {errors.status && (
                <p className="mt-2 text-sm text-destructive">{errors.status}</p>
            )}

            <dl className="mt-4 grid max-w-3xl gap-x-6 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]">
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
                {prescription.sale && (
                    <>
                        <dt className="text-muted-foreground">Dispensed as</dt>
                        <dd>
                            <Link
                                href={showSale(prescription.sale.id)}
                                className="underline"
                            >
                                {prescription.sale.number}
                            </Link>
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

            <ul className="mt-6 divide-y rounded-md border">
                {prescription.items.map((item) => (
                    <li
                        key={item.id}
                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm"
                    >
                        <span className="font-medium">{item.medicine}</span>
                        {item.controlled && (
                            <Badge variant="destructive">Controlled</Badge>
                        )}
                        <span>
                            {item.quantity} {item.unit}
                        </span>
                        <span className="text-muted-foreground">
                            {item.directions}
                        </span>
                        <span
                            className={`ml-auto ${item.available < item.quantity ? 'text-destructive' : 'text-muted-foreground'}`}
                        >
                            {item.available} in stock
                        </span>
                    </li>
                ))}
            </ul>

            <div className="mt-6 flex flex-wrap gap-3">
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
                    className="mt-4 flex max-w-xl flex-wrap items-start gap-2"
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
                <Form
                    {...dispense.form(prescription.id)}
                    className="mt-6 grid max-w-xl gap-3 rounded-md border p-4"
                >
                    {({ processing }) => (
                        <>
                            <h2 className="font-semibold">
                                Dispense:{' '}
                                {formatMoney(
                                    prescription.totalMinor,
                                    currency ?? 'USD',
                                )}
                            </h2>
                            {short && (
                                <p className="text-sm text-destructive">
                                    Not enough stock in this branch for every
                                    medicine.
                                </p>
                            )}
                            <div className="grid gap-1.5">
                                <Label htmlFor="payment_method">Payment</Label>
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
                                disabled={processing || short}
                            >
                                Dispense and take payment
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </>
    );
}
