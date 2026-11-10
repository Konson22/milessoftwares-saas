import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import { useMoney } from '@/lib/use-money';
import { approve, reject, reopen, review, submit } from '@/routes/shifts';
import {
    store as assign,
    destroy as unassign,
} from '@/routes/shifts/assignments';

type Detail = {
    nozzles: {
        nozzle_id: string;
        name: string;
        code: string;
        openingLitres: string;
        closingLitres: string | null;
        dispensedLitres: string | null;
        soldLitres: string;
        varianceLitres: string | null;
    }[];
    methods: { kind: string; currency?: string; amount_minor: number }[];
    sales_minor: number;
    expected_minor: number;
    difference_minor: number | null;
    assignments: {
        id: string;
        attendant: string;
        nozzle: string;
        expected_minor: number;
        counted_minor: number | null;
    }[];
};

type Props = {
    shift: {
        id: string;
        number: string;
        status: string;
        businessDate: string;
        openingFloatMinor: number;
        closingNote: string | null;
        rejectionReason: string | null;
        submittedBy: string | null;
    };
    detail: Detail;
    attendants: { id: string; name: string }[];
    nozzles: { id: string; name: string; code: string }[];
    canOpen: boolean;
    canClose: boolean;
    canApprove: boolean;
    canCorrect: boolean;
    currencies?: { code: string; name: string }[];
    books?: string;
    accountability?: {
        attendants: number;
        assignments: number;
        openAssignments: number;
        pendingHandovers: number;
        completedHandovers: number;
        openExceptions: number;
        pendingDeliveries?: number;
        waterAlerts?: number;
    };
};

export default function ShiftShow({
    shift,
    detail,
    attendants,
    nozzles,
    canOpen,
    canClose,
    canApprove,
    currencies = [],
    books,
    accountability,
}: Props) {
    const money = useMoney();
    const { errors, user, currency } = usePage().props;
    const bookCurrency = books ?? currency ?? 'SSP';
    const open = shift.status === 'open' || shift.status === 'rejected';

    return (
        <>
            <Head title={shift.number} />
            <PageHeader
                title={shift.number}
                description={`${shift.businessDate} · ${shift.status}. Float ${money(shift.openingFloatMinor)}. Expected cash ${money(detail.expected_minor)}.`}
            />
            {accountability && (
                <p className="mb-4 text-sm text-muted-foreground">
                    {accountability.attendants} attendants,{' '}
                    {accountability.assignments} assignment periods,{' '}
                    {accountability.openAssignments} still dispensing,{' '}
                    {accountability.completedHandovers} completed handovers,{' '}
                    {accountability.pendingHandovers} pending handovers,{' '}
                    {accountability.openExceptions} open exceptions. Ending one
                    assignment does not close this shift.
                    {typeof accountability.pendingDeliveries === 'number'
                        ? ` ${accountability.pendingDeliveries} deliveries still pending and ${accountability.waterAlerts ?? 0} water alerts. A pending calibration does not hold the cash closing.`
                        : ''}
                </p>
            )}
            {shift.rejectionReason && (
                <p className="mb-4 text-sm text-destructive">
                    {shift.rejectionReason}
                </p>
            )}
            <Panel className="mb-4">
                <PanelHeader title="Nozzles" />
                <PanelBody>
                    <ul className="grid gap-2 text-sm">
                        {detail.nozzles.map((row) => (
                            <li key={row.nozzle_id}>
                                {row.code}: opening {row.openingLitres} L
                                {row.closingLitres
                                    ? `, closing ${row.closingLitres} L, dispensed ${row.dispensedLitres} L`
                                    : ''}
                                , sold {row.soldLitres} L
                            </li>
                        ))}
                    </ul>
                    <ul className="mt-3 text-sm">
                        {detail.methods.map((row) => (
                            <li key={`${row.kind}-${row.currency ?? ''}`}>
                                {row.kind}
                                {row.currency ? ` ${row.currency}` : ''}:{' '}
                                {money(row.amount_minor)}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-3 text-sm">
                        Sales {money(detail.sales_minor)}. Cash difference{' '}
                        {detail.difference_minor === null
                            ? 'not counted'
                            : money(detail.difference_minor)}
                        .
                    </p>
                </PanelBody>
            </Panel>
            <Panel className="mb-4">
                <PanelHeader title="Assignments" />
                <PanelBody className="grid gap-3">
                    {detail.assignments.map((row) => (
                        <div
                            key={row.id}
                            className="flex items-center justify-between gap-2 text-sm"
                        >
                            <span>
                                {row.attendant} on {row.nozzle} · expected{' '}
                                {money(row.expected_minor)}
                            </span>
                            {canOpen && open && (
                                <Form {...unassign.form([shift.id, row.id])}>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                    >
                                        Remove
                                    </Button>
                                </Form>
                            )}
                        </div>
                    ))}
                    {canOpen && open && (
                        <Form
                            {...assign.form(shift.id)}
                            className="grid gap-3 md:grid-cols-3"
                        >
                            <Select
                                name="attendant_id"
                                aria-label="Attendant"
                                required
                                placeholder="Attendant"
                                options={attendants.map((row) => ({
                                    value: row.id,
                                    label: row.name,
                                }))}
                            />
                            <Select
                                name="nozzle_id"
                                aria-label="Nozzle"
                                required
                                placeholder="Nozzle"
                                options={nozzles.map((row) => ({
                                    value: row.id,
                                    label: row.code,
                                }))}
                            />
                            <Button type="submit">Assign</Button>
                        </Form>
                    )}
                </PanelBody>
            </Panel>
            {canClose && open && (
                <Panel className="mb-4">
                    <PanelHeader title="Submit closing" />
                    <PanelBody>
                        <Form {...submit.form(shift.id)} className="grid gap-3">
                            {detail.nozzles.map((row, index) => (
                                <div
                                    key={row.nozzle_id}
                                    className="grid gap-2 md:grid-cols-2"
                                >
                                    <input
                                        type="hidden"
                                        name={`closings[${index}][nozzle_id]`}
                                        value={row.nozzle_id}
                                    />
                                    <Field
                                        id={`closing-${row.code}`}
                                        label={`${row.code} closing litres`}
                                    >
                                        <Input
                                            id={`closing-${row.code}`}
                                            name={`closings[${index}][reading]`}
                                            inputMode="decimal"
                                            required
                                        />
                                    </Field>
                                </div>
                            ))}
                            {detail.assignments.map((row, index) => (
                                <div key={row.id}>
                                    <input
                                        type="hidden"
                                        name={`counts[${index}][id]`}
                                        value={row.id}
                                    />
                                    <Field
                                        id={`count-${row.id}`}
                                        label={`${row.attendant} counted cash`}
                                    >
                                        <Input
                                            id={`count-${row.id}`}
                                            name={`counts[${index}][amount]`}
                                            inputMode="decimal"
                                            defaultValue="0.00"
                                            required
                                        />
                                    </Field>
                                </div>
                            ))}
                            {currencies
                                .filter((row) => row.code !== bookCurrency)
                                .map((row, index) => (
                                    <Field
                                        key={row.code}
                                        id={`counted-${row.code}`}
                                        label={`Counted ${row.code}`}
                                        hint={`Only the ${row.code} in the drawer. ${bookCurrency} is counted below.`}
                                    >
                                        <input
                                            type="hidden"
                                            name={`foreign_counts[${index}][currency]`}
                                            value={row.code}
                                        />
                                        <Input
                                            id={`counted-${row.code}`}
                                            name={`foreign_counts[${index}][amount]`}
                                            inputMode="decimal"
                                            defaultValue="0.00"
                                        />
                                    </Field>
                                ))}
                            <Field
                                id="counted_drawer"
                                label={`Counted drawer (${bookCurrency})`}
                                error={errors.counted_drawer}
                            >
                                <Input
                                    id="counted_drawer"
                                    name="counted_drawer"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="note" label="Note">
                                <Input id="note" name="note" />
                            </Field>
                            <Button type="submit">Submit closing</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            {canApprove && shift.status === 'closing_submitted' && (
                <Form {...review.form(shift.id)} className="mb-3">
                    <Button type="submit">Start review</Button>
                </Form>
            )}
            {canApprove && shift.status === 'under_review' && user && (
                <div className="flex flex-wrap gap-3">
                    <Form {...approve.form(shift.id)}>
                        <Button type="submit">Approve</Button>
                    </Form>
                    <Form {...reject.form(shift.id)} className="flex gap-2">
                        <Input
                            name="reason"
                            placeholder="Reason"
                            aria-label="Rejection reason"
                            required
                        />
                        <Button type="submit" variant="outline">
                            Reject
                        </Button>
                    </Form>
                </div>
            )}
            {canApprove && shift.status === 'rejected' && (
                <Form {...reopen.form(shift.id)}>
                    <Button type="submit" variant="outline">
                        Reopen
                    </Button>
                </Form>
            )}
        </>
    );
}
