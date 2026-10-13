import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@/components/panel';
import { StatusBadge, statusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

            <Panel className="mb-5 max-w-3xl">
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
                        {prescription.sale && (
                            <>
                                <dt className="text-muted-foreground">
                                    Dispensed as
                                </dt>
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
                </PanelBody>
            </Panel>

            <Panel className="mb-5">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medicine</TableHead>
                            <TableHead>Quantity</TableHead>
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
                                <TableCell className="text-muted-foreground">
                                    {item.directions}
                                </TableCell>
                                <TableCell
                                    className={`text-right tabular-nums ${item.available < item.quantity ? 'text-destructive' : 'text-muted-foreground'}`}
                                >
                                    {item.available}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>

            <div className="mb-5 flex flex-wrap gap-3">
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
                    className="mb-5 flex max-w-xl flex-wrap items-start gap-2"
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
                        title={`Dispense: ${formatMoney(prescription.totalMinor, currency ?? 'USD')}`}
                    />
                    <PanelBody>
                        <Form
                            {...dispense.form(prescription.id)}
                            className="grid gap-3"
                        >
                            {({ processing }) => (
                                <>
                                    {short && (
                                        <p className="text-sm text-destructive">
                                            Not enough stock in this branch for
                                            every medicine.
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
                                        disabled={processing || short}
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
