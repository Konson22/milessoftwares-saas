import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import { store } from '@/routes/sales';

type Props = {
    shift: { id: string; number: string } | null;
    nozzles: { id: string; name: string; code: string }[];
    attendants: { id: string; name: string }[];
    methods: { id: string; name: string; kind: string }[];
    customers: { id: string; name: string }[];
    idempotencyKey: string;
    canCollectForOthers: boolean;
};

export default function CreateSale({
    shift,
    nozzles,
    attendants,
    methods,
    customers,
    idempotencyKey,
    canCollectForOthers,
}: Props) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="New sale" />
            <PageHeader
                title="New sale"
                description={
                    shift
                        ? `On shift ${shift.number}. Enter litres or an amount, not both.`
                        : 'Open a shift before selling.'
                }
            />
            {shift && (
                <Panel>
                    <PanelBody>
                        <Form
                            {...store.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <input
                                type="hidden"
                                name="idempotency_key"
                                value={idempotencyKey}
                            />
                            <Field
                                id="nozzle_id"
                                label="Nozzle"
                                error={errors.nozzle_id}
                            >
                                <Select
                                    id="nozzle_id"
                                    name="nozzle_id"
                                    required
                                    placeholder="Nozzle"
                                    options={nozzles.map((row) => ({
                                        value: row.id,
                                        label: row.code,
                                    }))}
                                />
                            </Field>
                            <Field
                                id="attendant_id"
                                label="Attendant"
                                error={errors.attendant_id}
                            >
                                <Select
                                    id="attendant_id"
                                    name="attendant_id"
                                    required
                                    placeholder="Attendant"
                                    options={attendants.map((row) => ({
                                        value: row.id,
                                        label: row.name,
                                    }))}
                                />
                            </Field>
                            <Field
                                id="litres"
                                label="Litres"
                                error={errors.litres}
                            >
                                <Input
                                    id="litres"
                                    name="litres"
                                    inputMode="decimal"
                                />
                            </Field>
                            <Field
                                id="amount"
                                label="Or amount"
                                error={errors.amount}
                            >
                                <Input
                                    id="amount"
                                    name="amount"
                                    inputMode="decimal"
                                />
                            </Field>
                            <Field id="collected_by" label="Collected by">
                                <Select
                                    id="collected_by"
                                    name="collected_by"
                                    defaultValue={
                                        canCollectForOthers
                                            ? 'cashier'
                                            : 'attendant'
                                    }
                                    options={[
                                        {
                                            value: 'attendant',
                                            label: 'Attendant',
                                        },
                                        { value: 'cashier', label: 'Cashier' },
                                    ]}
                                />
                            </Field>
                            <Field
                                id="method_id"
                                label="Payment"
                                error={errors.payments}
                            >
                                <Select
                                    id="method_id"
                                    name="payments[0][method_id]"
                                    required
                                    placeholder="Method"
                                    options={methods.map((row) => ({
                                        value: row.id,
                                        label: row.name,
                                    }))}
                                />
                            </Field>
                            <Field id="pay-amount" label="Payment amount">
                                <Input
                                    id="pay-amount"
                                    name="payments[0][amount]"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="customer_id" label="Credit customer">
                                <Select
                                    id="customer_id"
                                    name="payments[0][customer_id]"
                                    placeholder="None"
                                    options={customers.map((row) => ({
                                        value: row.id,
                                        label: row.name,
                                    }))}
                                />
                            </Field>
                            <div className="md:col-span-2">
                                <Button type="submit">Record sale</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
