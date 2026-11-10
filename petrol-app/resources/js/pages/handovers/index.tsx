import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field } from '@/components/field';

type Assignment = {
    id: string;
    attendant: string;
    attendantId: string;
    pump: string;
    nozzle: string;
    status: string;
    startedAt: string | null;
    endedAt: string | null;
    opening: string | null;
    closing: string | null;
    active: boolean;
};

export default function Handovers({
    shift,
    assignments,
    handovers,
    attendants,
    canInitiate,
    canConfirm,
    canApprove,
    canReassign,
    breaks = [],
}: {
    shift: { id: string; number: string } | null;
    assignments: Assignment[];
    handovers: {
        id: string;
        nozzle: string;
        status: string;
        incoming: string;
        closing: string;
        opening: string;
        event: string | null;
    }[];
    attendants: { id: string; name: string }[];
    canInitiate: boolean;
    canConfirm: boolean;
    canApprove: boolean;
    canReassign: boolean;
    breaks?: {
        id: string;
        reason: string;
        ended_at: string | null;
        nozzle_available: boolean;
    }[];
}) {
    const { errors } = usePage().props;
    const active = assignments.filter((row) => row.active);
    const byPump = assignments.reduce<Record<string, Assignment[]>>(
        (groups, row) => {
            const key = `${row.pump} ${row.nozzle}`;
            groups[key] = [...(groups[key] ?? []), row];
            return groups;
        },
        {},
    );

    return (
        <>
            <Head title="Handovers" />
            <PageHeader
                title="Attendant handovers"
                description={
                    shift
                        ? `Shift ${shift.number} stays open. Cash already collected stays with the attendant who took it.`
                        : 'Open a shift before handing over a pump.'
                }
            />
            <Panel className="mb-4">
                <PanelHeader title="Assignment timeline" />
                <PanelBody className="grid gap-3 text-sm">
                    {Object.entries(byPump).map(([pump, rows]) => (
                        <div key={pump}>
                            <p className="font-medium">{pump}</p>
                            <ul>
                                {rows.map((row) => (
                                    <li key={row.id}>
                                        {row.startedAt?.slice(11, 16) ?? '—'}–
                                        {row.endedAt?.slice(11, 16) ?? 'now'}{' '}
                                        {row.attendant} · {row.status}
                                        {row.opening
                                            ? ` · open ${row.opening} L`
                                            : ''}
                                        {row.closing
                                            ? ` · close ${row.closing} L`
                                            : ''}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                    {assignments.length === 0 && (
                        <p className="text-muted-foreground">
                            No assignments on the open shift.
                        </p>
                    )}
                </PanelBody>
            </Panel>
            {canInitiate && active.length > 0 && (
                <Panel className="mb-4">
                    <PanelHeader title="Request a handover" />
                    <PanelBody>
                        <Form
                            action="/petrol/handovers"
                            method="post"
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field
                                id="assignment_id"
                                label="Outgoing assignment"
                                error={errors.assignment_id}
                            >
                                <select
                                    id="assignment_id"
                                    name="assignment_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {active.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.attendant} · {row.nozzle}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                id="incoming_attendant_id"
                                label="Incoming attendant"
                                error={errors.incoming_attendant_id}
                            >
                                <select
                                    id="incoming_attendant_id"
                                    name="incoming_attendant_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {attendants.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                id="closing"
                                label="Closing reading (litres)"
                                error={errors.closing}
                            >
                                <Input
                                    id="closing"
                                    name="closing"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field
                                id="opening"
                                label="Incoming opening (litres)"
                                error={errors.opening}
                            >
                                <Input
                                    id="opening"
                                    name="opening"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field
                                id="event_reason"
                                label="Meter event"
                                hint="Required only when the two readings differ."
                                error={errors.event_reason}
                            >
                                <Input id="event_reason" name="event_reason" />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Request handover</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            {canReassign && active.length > 0 && (
                <Panel className="mb-4">
                    <PanelHeader title="Supervisor reassignment or early departure" />
                    <PanelBody className="grid gap-4">
                        <Form
                            action="/petrol/handovers/reassign"
                            method="post"
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field
                                id="reassign_assignment"
                                label="Current assignment"
                            >
                                <select
                                    id="reassign_assignment"
                                    name="assignment_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {active.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.attendant} · {row.nozzle}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field id="reassign_incoming" label="Replacement">
                                <select
                                    id="reassign_incoming"
                                    name="incoming_attendant_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {attendants.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field id="reassign_closing" label="Closing litres">
                                <Input
                                    id="reassign_closing"
                                    name="closing"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="reassign_opening" label="Opening litres">
                                <Input
                                    id="reassign_opening"
                                    name="opening"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="reassign_event" label="Meter event">
                                <Input
                                    id="reassign_event"
                                    name="event_reason"
                                />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Reassign</Button>
                            </div>
                        </Form>
                        <Form
                            action="/petrol/handovers/depart"
                            method="post"
                            className="grid gap-3 md:grid-cols-3"
                        >
                            <Field
                                id="depart_assignment"
                                label="Leaving assignment"
                            >
                                <select
                                    id="depart_assignment"
                                    name="assignment_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {active.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.attendant} · {row.nozzle}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field id="depart_closing" label="Closing litres">
                                <Input
                                    id="depart_closing"
                                    name="closing"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <div className="self-end">
                                <Button type="submit" variant="outline">
                                    End dispensing
                                </Button>
                            </div>
                        </Form>
                        <Form
                            action="/petrol/handovers/breaks"
                            method="post"
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field id="break_assignment" label="Assignment">
                                <select
                                    id="break_assignment"
                                    name="assignment_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {active.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.attendant} · {row.nozzle}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                id="break_reading"
                                label="Meter now (litres)"
                            >
                                <Input
                                    id="break_reading"
                                    name="reading"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="break_reason" label="Reason">
                                <Input
                                    id="break_reason"
                                    name="reason"
                                    required
                                />
                            </Field>
                            <Field
                                id="replacement_id"
                                label="Covering attendant"
                            >
                                <select
                                    id="replacement_id"
                                    name="replacement_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                >
                                    <option value="">
                                        Nobody — flag the gap
                                    </option>
                                    {attendants.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Button type="submit" variant="outline">
                                Start break
                            </Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            <Panel>
                <PanelHeader title="Handover record" />
                <PanelBody className="grid gap-3 text-sm">
                    {handovers.map((row) => (
                        <div key={row.id} className="border-b pb-3">
                            <p>
                                {row.nozzle} → {row.incoming} · {row.status} ·
                                close {row.closing} L · open {row.opening} L
                                {row.event ? ` · ${row.event}` : ''}
                            </p>
                            {row.status === 'requested' && canConfirm && (
                                <Form
                                    action={`/petrol/handovers/${row.id}/confirm`}
                                    method="post"
                                    className="mt-2"
                                >
                                    <Button type="submit" variant="outline">
                                        Verify opening
                                    </Button>
                                </Form>
                            )}
                            {(row.status === 'requested' ||
                                row.status === 'verified') &&
                                canApprove && (
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <Form
                                            action={`/petrol/handovers/${row.id}/approve`}
                                            method="post"
                                        >
                                            <Button type="submit">
                                                Approve
                                            </Button>
                                        </Form>
                                        <Form
                                            action={`/petrol/handovers/${row.id}/reject`}
                                            method="post"
                                            className="flex gap-2"
                                        >
                                            <Input
                                                name="notes"
                                                placeholder="Why it was rejected"
                                                required
                                            />
                                            <Button
                                                type="submit"
                                                variant="outline"
                                            >
                                                Reject
                                            </Button>
                                        </Form>
                                    </div>
                                )}
                        </div>
                    ))}
                    {handovers.length === 0 && (
                        <p className="text-muted-foreground">
                            No handovers yet.
                        </p>
                    )}
                    {breaks
                        .filter((row) => row.ended_at === null)
                        .map((row) => (
                            <Form
                                key={row.id}
                                action={`/petrol/handovers/breaks/${row.id}/end`}
                                method="post"
                                className="grid gap-3 border-t pt-3 md:grid-cols-3"
                            >
                                <p className="text-sm md:col-span-3">
                                    {row.reason}
                                    {row.nozzle_available
                                        ? ''
                                        : ' · nozzle left unattended'}
                                </p>
                                <Field
                                    id={`return_${row.id}`}
                                    label="Return reading (litres)"
                                >
                                    <Input
                                        id={`return_${row.id}`}
                                        name="reading"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <div className="self-end">
                                    <Button type="submit" variant="outline">
                                        End break
                                    </Button>
                                </div>
                            </Form>
                        ))}
                </PanelBody>
            </Panel>
        </>
    );
}
