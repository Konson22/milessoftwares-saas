import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { index, show, store, update } from '@/routes/vehicles';
import type { Option } from '@/types';

type Vehicle = {
    id: string;
    registration: string;
    kind: string;
    description: string | null;
    capacityKg: number | null;
    odometerKm: number;
    status: string;
};

const STATUSES: Option[] = [
    { value: 'available', label: 'Available for trips' },
    { value: 'maintenance', label: 'In maintenance' },
    { value: 'retired', label: 'Retired' },
];

/**
 * Adding a vehicle to the fleet or changing one.
 */
export default function VehicleForm({
    vehicle,
    kinds,
}: {
    vehicle: Vehicle | null;
    kinds: Option[];
}) {
    const action = vehicle ? update.form(vehicle.id) : store.form();
    const title = vehicle ? vehicle.registration : 'Add a vehicle';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={vehicle ? show(vehicle.id) : index()}>
                            {vehicle ? '← Vehicle' : '← Vehicles'}
                        </Link>
                    </Button>
                }
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-2">
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="registration"
                                    label="Registration"
                                    error={errors.registration}
                                >
                                    <Input
                                        id="registration"
                                        name="registration"
                                        required
                                        defaultValue={vehicle?.registration}
                                        {...invalid(
                                            'registration',
                                            errors.registration,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="kind"
                                    label="Kind"
                                    error={errors.kind}
                                >
                                    <Select
                                        id="kind"
                                        name="kind"
                                        defaultValue={vehicle?.kind ?? 'truck'}
                                        options={kinds}
                                    />
                                </Field>
                                <Field
                                    id="description"
                                    label="Make and model"
                                    error={errors.description}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="description"
                                        name="description"
                                        placeholder="Isuzu FVR, 10 tonne"
                                        defaultValue={
                                            vehicle?.description ?? ''
                                        }
                                    />
                                </Field>
                                <Field
                                    id="capacity_kg"
                                    label="Capacity (kg)"
                                    error={errors.capacity_kg}
                                >
                                    <Input
                                        id="capacity_kg"
                                        name="capacity_kg"
                                        type="number"
                                        min={1}
                                        defaultValue={vehicle?.capacityKg ?? ''}
                                        {...invalid(
                                            'capacity_kg',
                                            errors.capacity_kg,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="odometer_km"
                                    label="Odometer (km)"
                                    error={errors.odometer_km}
                                    hint="Updated by trips and maintenance."
                                >
                                    <Input
                                        id="odometer_km"
                                        name="odometer_km"
                                        type="number"
                                        min={0}
                                        defaultValue={vehicle?.odometerKm ?? 0}
                                        {...invalid(
                                            'odometer_km',
                                            errors.odometer_km,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="status"
                                    label="Status"
                                    error={errors.status}
                                    className="sm:col-span-2"
                                >
                                    <Select
                                        id="status"
                                        name="status"
                                        defaultValue={
                                            vehicle?.status ?? 'available'
                                        }
                                        options={STATUSES}
                                    />
                                </Field>
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {vehicle ? 'Save' : 'Add vehicle'}
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
