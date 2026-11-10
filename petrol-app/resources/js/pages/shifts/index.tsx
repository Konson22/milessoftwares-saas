import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import { show, store } from '@/routes/shifts';

const tone = {
    open: 'info',
    closing_submitted: 'warning',
    under_review: 'warning',
    approved: 'success',
    rejected: 'danger',
} as const;

export default function Shifts({
    shifts,
    schedules,
    canOpen,
    currencies = [],
    books,
}: {
    shifts: {
        id: string;
        number: string;
        status: keyof typeof tone;
        businessDate: string;
        openedAt: string;
    }[];
    schedules: {
        id: string;
        name: string;
        starts_at: string;
        ends_at: string;
    }[];
    canOpen: boolean;
    currencies?: { code: string; name: string }[];
    books?: string;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Shifts" />
            <PageHeader
                title="Shifts"
                description="One shift is open at a station. The person who submits the closing cannot approve it."
            />
            <Panel className="mb-4">
                <PanelHeader title="Recent" />
                <PanelBody>
                    <ul className="grid gap-2 text-sm">
                        {shifts.map((shift) => (
                            <li
                                key={shift.id}
                                className="flex items-center gap-2"
                            >
                                <Link
                                    href={show(shift.id)}
                                    className="underline"
                                >
                                    {shift.number}
                                </Link>
                                <span>{shift.businessDate}</span>
                                <StatusBadge tone={tone[shift.status]}>
                                    {shift.status}
                                </StatusBadge>
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
            {canOpen && (
                <Panel>
                    <PanelHeader title="Open a shift" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field
                                id="opening_float"
                                label="Opening float"
                                error={errors.opening_float}
                            >
                                <Input
                                    id="opening_float"
                                    name="opening_float"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="schedule_id" label="Schedule">
                                <Select
                                    id="schedule_id"
                                    name="schedule_id"
                                    placeholder="None"
                                    options={schedules.map((row) => ({
                                        value: row.id,
                                        label: `${row.name} ${row.starts_at}–${row.ends_at}`,
                                    }))}
                                />
                            </Field>
                            {currencies
                                .filter((row) => row.code !== books)
                                .map((row, index) => (
                                    <Field
                                        key={row.code}
                                        id={`float-${row.code}`}
                                        label={`Opening float (${row.code})`}
                                    >
                                        <input
                                            type="hidden"
                                            name={`foreign_floats[${index}][currency]`}
                                            value={row.code}
                                        />
                                        <Input
                                            id={`float-${row.code}`}
                                            name={`foreign_floats[${index}][amount]`}
                                            inputMode="decimal"
                                            defaultValue="0.00"
                                        />
                                    </Field>
                                ))}
                            <Field
                                id="handover_note"
                                label="Handover note"
                                className="md:col-span-2"
                            >
                                <Input
                                    id="handover_note"
                                    name="handover_note"
                                />
                            </Field>
                            <Button type="submit">Open shift</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
