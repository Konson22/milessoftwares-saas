import { Form, Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field } from '@/components/field';

export default function Calibration({
    calibrations,
    draws,
    events,
    nozzles,
    tanks,
    canRecord,
    canApprove,
    canEquipment,
}: {
    calibrations: {
        id: string;
        on: string;
        technician: string;
        indicated: string;
        measured: string;
        difference: string;
        outcome: string;
        nextDue: string | null;
    }[];
    draws: { id: string; litres: string; status: string; purpose: string }[];
    events: { type: string; previous: string; next: string; at: string }[];
    nozzles: { id: string; code: string; name: string }[];
    tanks: { id: string; code: string }[];
    canRecord: boolean;
    canApprove: boolean;
    canEquipment: boolean;
}) {
    return (
        <>
            <Head title="Calibration" />
            <PageHeader
                title="Pump calibration"
                description="A proving-can test is not a customer sale. Returning the fuel puts it back in the tank once."
            />
            {canRecord && (
                <div className="mb-4 grid gap-4 lg:grid-cols-2">
                    <Panel>
                        <PanelHeader title="Calibration result" />
                        <PanelBody>
                            <Form
                                action="/petrol/calibration"
                                method="post"
                                className="grid gap-3"
                            >
                                <Field id="cal_nozzle" label="Nozzle">
                                    <select
                                        id="cal_nozzle"
                                        name="nozzle_id"
                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                        required
                                    >
                                        {nozzles.map((row) => (
                                            <option key={row.id} value={row.id}>
                                                {row.code}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field id="calibrated_on" label="Date">
                                    <Input
                                        id="calibrated_on"
                                        name="calibrated_on"
                                        type="date"
                                        required
                                    />
                                </Field>
                                <Field id="technician" label="Technician">
                                    <Input
                                        id="technician"
                                        name="technician"
                                        required
                                    />
                                </Field>
                                <Field id="indicated" label="Meter litres">
                                    <Input
                                        id="indicated"
                                        name="indicated"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Field id="measured" label="Can litres">
                                    <Input
                                        id="measured"
                                        name="measured"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Field id="test" label="Test quantity">
                                    <Input
                                        id="test"
                                        name="test"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Field id="outcome" label="Outcome">
                                    <select
                                        id="outcome"
                                        name="outcome"
                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                    >
                                        <option value="pass">Pass</option>
                                        <option value="fail">Fail</option>
                                    </select>
                                </Field>
                                <Button type="submit">Save calibration</Button>
                            </Form>
                        </PanelBody>
                    </Panel>
                    <Panel>
                        <PanelHeader title="Test dispensing" />
                        <PanelBody>
                            <Form
                                action="/petrol/calibration/draws"
                                method="post"
                                className="grid gap-3"
                            >
                                <Field id="draw_nozzle" label="Nozzle">
                                    <select
                                        id="draw_nozzle"
                                        name="nozzle_id"
                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                        required
                                    >
                                        {nozzles.map((row) => (
                                            <option key={row.id} value={row.id}>
                                                {row.code}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field id="draw_tank" label="Tank">
                                    <select
                                        id="draw_tank"
                                        name="tank_id"
                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                        required
                                    >
                                        {tanks.map((row) => (
                                            <option key={row.id} value={row.id}>
                                                {row.code}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field id="draw_litres" label="Litres">
                                    <Input
                                        id="draw_litres"
                                        name="litres"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Button type="submit" variant="outline">
                                    Record test
                                </Button>
                            </Form>
                        </PanelBody>
                    </Panel>
                </div>
            )}
            {canEquipment && (
                <Panel className="mb-4">
                    <PanelHeader title="Meter replacement, reset, or rollover" />
                    <PanelBody>
                        <Form
                            action="/petrol/calibration/events"
                            method="post"
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field id="event_nozzle" label="Nozzle">
                                <select
                                    id="event_nozzle"
                                    name="nozzle_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {nozzles.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.code}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field id="event_type" label="Event">
                                <select
                                    id="event_type"
                                    name="type"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                >
                                    <option value="replacement">
                                        Replacement
                                    </option>
                                    <option value="reset">Reset</option>
                                    <option value="rollover">Rollover</option>
                                    <option value="calibration">
                                        Calibration
                                    </option>
                                </select>
                            </Field>
                            <Field
                                id="previous"
                                label="Previous reading (litres)"
                            >
                                <Input
                                    id="previous"
                                    name="previous"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="next" label="New reading (litres)">
                                <Input
                                    id="next"
                                    name="next"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="event_technician" label="Technician">
                                <Input
                                    id="event_technician"
                                    name="technician"
                                    required
                                />
                            </Field>
                            <Button type="submit" variant="outline">
                                Record event
                            </Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            <Panel className="mb-4">
                <PanelHeader title="History" />
                <PanelBody className="grid gap-2 text-sm">
                    {calibrations.map((row) => (
                        <p key={row.id}>
                            {row.on} · meter {row.indicated} L · can{' '}
                            {row.measured} L · difference {row.difference} L ·{' '}
                            {row.outcome}
                            {row.nextDue ? ` · due ${row.nextDue}` : ''}
                        </p>
                    ))}
                    {draws.map((row) => (
                        <div
                            key={row.id}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <span>
                                {row.purpose} {row.litres} L · {row.status}
                            </span>
                            {row.status === 'recorded' && canApprove && (
                                <Form
                                    action={`/petrol/calibration/draws/${row.id}/approve`}
                                    method="post"
                                >
                                    <Button type="submit" variant="outline">
                                        Approve
                                    </Button>
                                </Form>
                            )}
                            {row.status === 'approved' && canApprove && (
                                <Form
                                    action={`/petrol/calibration/draws/${row.id}/return`}
                                    method="post"
                                >
                                    <Button type="submit" variant="outline">
                                        Return to tank
                                    </Button>
                                </Form>
                            )}
                        </div>
                    ))}
                    {events.map((row, index) => (
                        <p key={`${row.at}-${index}`}>
                            {row.type} · {row.previous} L → {row.next} L
                        </p>
                    ))}
                </PanelBody>
            </Panel>
        </>
    );
}
