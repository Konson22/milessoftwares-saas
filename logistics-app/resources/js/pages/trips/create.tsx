import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { index, store } from '@/routes/trips';

type Choice = { id: string; label: string };

type Props = {
    vehicles: Choice[];
    drivers: Choice[];
    today: string;
};

/**
 * Planning a trip: a vehicle, a driver and a day. Shipments are loaded next.
 */
export default function PlanTrip({ vehicles, drivers, today }: Props) {
    const options = (choices: Choice[]) =>
        choices.map((choice) => ({ value: choice.id, label: choice.label }));

    return (
        <>
            <Head title="Plan a trip" />
            <PageHeader
                title="Plan a trip"
                description="Choose the vehicle, driver and day; load the shipments next."
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Trips</Link>
                    </Button>
                }
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    <Form
                        {...store.form()}
                        className="grid gap-4 sm:grid-cols-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="vehicle_id"
                                    label="Vehicle"
                                    error={errors.vehicle_id}
                                    hint={
                                        vehicles.length === 0
                                            ? 'No vehicle is available: add one or bring one back from maintenance.'
                                            : undefined
                                    }
                                >
                                    <Select
                                        id="vehicle_id"
                                        name="vehicle_id"
                                        required
                                        defaultValue=""
                                        placeholder="Choose a vehicle"
                                        options={options(vehicles)}
                                        {...invalid(
                                            'vehicle_id',
                                            errors.vehicle_id,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="driver_id"
                                    label="Driver"
                                    error={errors.driver_id}
                                >
                                    <Select
                                        id="driver_id"
                                        name="driver_id"
                                        required
                                        defaultValue=""
                                        placeholder="Choose a driver"
                                        options={options(drivers)}
                                        {...invalid(
                                            'driver_id',
                                            errors.driver_id,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="planned_on"
                                    label="Day"
                                    error={errors.planned_on}
                                >
                                    <Input
                                        id="planned_on"
                                        name="planned_on"
                                        type="date"
                                        required
                                        defaultValue={today}
                                        {...invalid(
                                            'planned_on',
                                            errors.planned_on,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="route"
                                    label="Route"
                                    error={errors.route}
                                >
                                    <Input
                                        id="route"
                                        name="route"
                                        placeholder="Juba → Nimule"
                                    />
                                </Field>
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        Plan trip
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            </Panel>
        </>
    );
}
