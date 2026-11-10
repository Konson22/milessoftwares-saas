import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import { connect } from '@/routes/nozzles';
import { store, update } from '@/routes/pumps';
import { store as storeNozzle } from '@/routes/pumps/nozzles';

type Pump = {
    id: string;
    name: string;
    code: string;
    status: string;
    nozzles: {
        id: string;
        name: string;
        code: string;
        status: string;
        product: string;
        productId: string;
        tank: string | null;
    }[];
};

export default function Pumps({
    pumps,
    products,
    tanks,
    canManage,
}: {
    pumps: Pump[];
    products: { id: string; name: string }[];
    tanks: {
        id: string;
        code: string;
        name: string;
        fuel_product_id: string;
    }[];
    canManage: boolean;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Pumps" />
            <PageHeader
                title="Pumps and nozzles"
                description="A nozzle sells one grade and draws from one tank."
            />
            {pumps.map((pump) => (
                <Panel key={pump.id} className="mb-4">
                    <PanelHeader
                        title={`${pump.code} ${pump.name}`}
                        description={pump.status}
                    />
                    <PanelBody className="grid gap-3">
                        <ul className="text-sm">
                            {pump.nozzles.map((nozzle) => (
                                <li
                                    key={nozzle.id}
                                    className="grid gap-2 md:grid-cols-2"
                                >
                                    <span>
                                        {nozzle.code} {nozzle.product} →{' '}
                                        {nozzle.tank ?? 'not connected'}
                                    </span>
                                    {canManage && (
                                        <Form
                                            {...connect.form(nozzle.id)}
                                            className="flex gap-2"
                                        >
                                            <Select
                                                name="tank_id"
                                                aria-label="Tank"
                                                options={tanks
                                                    .filter(
                                                        (tank) =>
                                                            tank.fuel_product_id ===
                                                            nozzle.productId,
                                                    )
                                                    .map((tank) => ({
                                                        value: tank.id,
                                                        label: tank.code,
                                                    }))}
                                                placeholder="Tank"
                                                required
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                            >
                                                Connect
                                            </Button>
                                        </Form>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {canManage && (
                            <Form
                                {...storeNozzle.form(pump.id)}
                                className="grid gap-3 md:grid-cols-4"
                            >
                                <Select
                                    name="fuel_product_id"
                                    aria-label="Grade"
                                    required
                                    options={products.map((product) => ({
                                        value: product.id,
                                        label: product.name,
                                    }))}
                                    placeholder="Grade"
                                />
                                <Input
                                    name="name"
                                    placeholder="Name"
                                    aria-label="Nozzle name"
                                    required
                                />
                                <Input
                                    name="code"
                                    placeholder="Code"
                                    aria-label="Nozzle code"
                                    required
                                />
                                <Button type="submit">Add nozzle</Button>
                            </Form>
                        )}
                        {canManage && (
                            <Form
                                {...update.form(pump.id)}
                                className="flex gap-2"
                            >
                                <Select
                                    name="status"
                                    aria-label="Pump status"
                                    defaultValue={pump.status}
                                    options={[
                                        { value: 'active', label: 'Active' },
                                        {
                                            value: 'maintenance',
                                            label: 'Maintenance',
                                        },
                                        {
                                            value: 'out_of_service',
                                            label: 'Out of service',
                                        },
                                    ]}
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                >
                                    Update pump
                                </Button>
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            ))}
            {canManage && (
                <Panel>
                    <PanelHeader title="Add a pump" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            className="grid gap-3 md:grid-cols-4"
                        >
                            <Field
                                id="pump-name"
                                label="Name"
                                error={errors.name}
                            >
                                <Input id="pump-name" name="name" required />
                            </Field>
                            <Field
                                id="pump-code"
                                label="Code"
                                error={errors.code}
                            >
                                <Input id="pump-code" name="code" required />
                            </Field>
                            <Field id="pump-status" label="Status">
                                <Select
                                    id="pump-status"
                                    name="status"
                                    defaultValue="active"
                                    options={[
                                        { value: 'active', label: 'Active' },
                                        {
                                            value: 'maintenance',
                                            label: 'Maintenance',
                                        },
                                        {
                                            value: 'out_of_service',
                                            label: 'Out of service',
                                        },
                                    ]}
                                />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Add pump</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
