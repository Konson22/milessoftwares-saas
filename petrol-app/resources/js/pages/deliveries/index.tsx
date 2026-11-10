import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field } from '@/components/field';

type Receipt = {
    id: string;
    number: string;
    supplier: string;
    tank: string;
    status: string;
    declared: string;
    accepted: string | null;
    before: string | null;
    after: string | null;
    dispensed: string | null;
    expected: string | null;
    variance: string | null;
    reconciliation: string | null;
    open: boolean;
    ready: boolean;
};

export default function Deliveries({
    receipts,
    tanks,
    suppliers,
    canRecord,
    canApprove,
}: {
    receipts: Receipt[];
    tanks: { id: string; code: string; name: string }[];
    suppliers: { id: string; name: string }[];
    canRecord: boolean;
    canApprove: boolean;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Deliveries" />
            <PageHeader
                title="Fuel deliveries"
                description="The supplier quantity is kept. Stock increases by the accepted quantity only after a second person approves it. Sales during unloading are subtracted from the expected dip."
            />
            {canRecord && (
                <Panel className="mb-4">
                    <PanelHeader title="Start unloading" />
                    <PanelBody>
                        <Form
                            action="/petrol/deliveries"
                            method="post"
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field
                                id="supplier_id"
                                label="Supplier"
                                error={errors.supplier_id}
                            >
                                <select
                                    id="supplier_id"
                                    name="supplier_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {suppliers.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                id="tank_id"
                                label="Tank"
                                error={errors.tank_id}
                            >
                                <select
                                    id="tank_id"
                                    name="tank_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {tanks.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.code} {row.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                id="declared"
                                label="Declared litres"
                                error={errors.declared}
                            >
                                <Input
                                    id="declared"
                                    name="declared"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field
                                id="before"
                                label="Tank before (litres)"
                                error={errors.before}
                            >
                                <Input
                                    id="before"
                                    name="before"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field
                                id="unit_cost"
                                label="Cost per litre"
                                error={errors.unit_cost}
                            >
                                <Input
                                    id="unit_cost"
                                    name="unit_cost"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field
                                id="supplier_reference"
                                label="Delivery reference"
                            >
                                <Input
                                    id="supplier_reference"
                                    name="supplier_reference"
                                />
                            </Field>
                            <Button type="submit">Start</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            <Panel>
                <PanelHeader title="Deliveries" />
                <PanelBody className="grid gap-4 text-sm">
                    {receipts.map((row) => (
                        <div key={row.id} className="border-b pb-3">
                            <p>
                                {row.number} · {row.supplier} · {row.tank} ·{' '}
                                {row.status}
                                {row.reconciliation
                                    ? ` · ${row.reconciliation}`
                                    : ''}
                            </p>
                            <p>
                                Declared {row.declared} L
                                {row.accepted
                                    ? ` · accepted ${row.accepted} L`
                                    : ''}
                                {row.dispensed
                                    ? ` · dispensed during unloading ${row.dispensed} L`
                                    : ''}
                                {row.expected
                                    ? ` · expected ${row.expected} L`
                                    : ''}
                                {row.variance
                                    ? ` · difference ${row.variance} L`
                                    : ''}
                            </p>
                            {row.open && canRecord && (
                                <Form
                                    action={`/petrol/deliveries/${row.id}/complete`}
                                    method="post"
                                    className="mt-2 grid gap-2 md:grid-cols-3"
                                >
                                    <Input
                                        name="after"
                                        placeholder="Tank after, litres"
                                    />
                                    <Input
                                        name="accepted"
                                        placeholder="Accepted litres"
                                        required
                                    />
                                    <Button type="submit" variant="outline">
                                        Save measurements
                                    </Button>
                                </Form>
                            )}
                            {row.ready && canApprove && (
                                <Form
                                    action={`/petrol/deliveries/${row.id}/approve`}
                                    method="post"
                                    className="mt-2"
                                >
                                    <Button type="submit">
                                        Approve accepted quantity
                                    </Button>
                                </Form>
                            )}
                        </div>
                    ))}
                    {receipts.length === 0 && (
                        <p className="text-muted-foreground">
                            No deliveries yet.
                        </p>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
