import { Form, Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field } from '@/components/field';

export default function Exceptions({
    exceptions,
    shifts,
    canReview,
    canResolve,
}: {
    exceptions: {
        id: string;
        type: string;
        description: string;
        severity: string;
        status: string;
        resolution: string | null;
    }[];
    shifts: { id: string; number: string }[];
    canReview: boolean;
    canResolve: boolean;
}) {
    return (
        <>
            <Head title="Shift exceptions" />
            <PageHeader
                title="Shift exceptions"
                description="An exception stays on the shift after the reading or the cash is put right. Closing one does not delete it."
            />
            {canReview && shifts.length > 0 && (
                <Panel className="mb-4">
                    <PanelHeader title="Record an exception" />
                    <PanelBody>
                        <Form
                            action="/petrol/exceptions"
                            method="post"
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field id="shift_id" label="Shift">
                                <select
                                    id="shift_id"
                                    name="shift_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {shifts.map((shift) => (
                                        <option key={shift.id} value={shift.id}>
                                            {shift.number}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field id="type" label="Type">
                                <Input id="type" name="type" required />
                            </Field>
                            <Field id="description" label="What happened">
                                <Input
                                    id="description"
                                    name="description"
                                    required
                                />
                            </Field>
                            <Field id="severity" label="Severity">
                                <select
                                    id="severity"
                                    name="severity"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    defaultValue="warning"
                                >
                                    <option value="warning">Warning</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </Field>
                            <Button type="submit">Record</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            <Panel>
                <PanelHeader title="Open and closed" />
                <PanelBody className="grid gap-3 text-sm">
                    {exceptions.map((row) => (
                        <div key={row.id} className="border-b pb-3">
                            <p>
                                {row.type} · {row.severity} · {row.status}
                            </p>
                            <p>{row.description}</p>
                            {row.resolution && (
                                <p className="text-muted-foreground">
                                    {row.resolution}
                                </p>
                            )}
                            <div className="mt-2 flex flex-wrap gap-2">
                                {canReview && row.status === 'open' && (
                                    <Form
                                        action={`/petrol/exceptions/${row.id}/review`}
                                        method="post"
                                    >
                                        <Button type="submit" variant="outline">
                                            Review
                                        </Button>
                                    </Form>
                                )}
                                {canResolve &&
                                    (row.status === 'open' ||
                                        row.status === 'under_review') && (
                                        <Form
                                            action={`/petrol/exceptions/${row.id}/resolve`}
                                            method="post"
                                            className="flex gap-2"
                                        >
                                            <Input
                                                name="resolution"
                                                placeholder="How it was resolved"
                                                required
                                            />
                                            <Button type="submit">
                                                Resolve
                                            </Button>
                                        </Form>
                                    )}
                                {canResolve && row.status === 'resolved' && (
                                    <Form
                                        action={`/petrol/exceptions/${row.id}/close`}
                                        method="post"
                                    >
                                        <Button type="submit" variant="outline">
                                            Close
                                        </Button>
                                    </Form>
                                )}
                                {canResolve &&
                                    (row.status === 'resolved' ||
                                        row.status === 'closed') && (
                                        <Form
                                            action={`/petrol/exceptions/${row.id}/reopen`}
                                            method="post"
                                            className="flex gap-2"
                                        >
                                            <Input
                                                name="reason"
                                                placeholder="Why it is open again"
                                                required
                                            />
                                            <Button
                                                type="submit"
                                                variant="outline"
                                            >
                                                Reopen
                                            </Button>
                                        </Form>
                                    )}
                            </div>
                        </div>
                    ))}
                    {exceptions.length === 0 && (
                        <p className="text-muted-foreground">
                            No exceptions on this station.
                        </p>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
