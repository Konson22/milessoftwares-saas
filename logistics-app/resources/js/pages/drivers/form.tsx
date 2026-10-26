import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid } from '@/components/field';
import { index, store, update } from '@/routes/drivers';

type Driver = {
    id: string;
    name: string;
    phone: string | null;
    licenceNumber: string | null;
    licenceExpiresOn: string | null;
    active: boolean;
};

/**
 * Adding or changing a driver.
 */
export default function DriverForm({ driver }: { driver: Driver | null }) {
    const action = driver ? update.form(driver.id) : store.form();
    const title = driver ? driver.name : 'Add a driver';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Drivers</Link>
                    </Button>
                }
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-2">
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="name"
                                    label="Name"
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        defaultValue={driver?.name}
                                        {...invalid('name', errors.name)}
                                    />
                                </Field>
                                <Field
                                    id="phone"
                                    label="Phone"
                                    error={errors.phone}
                                >
                                    <Input
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        defaultValue={driver?.phone ?? ''}
                                    />
                                </Field>
                                <Field
                                    id="licence_number"
                                    label="Driving licence number"
                                    error={errors.licence_number}
                                >
                                    <Input
                                        id="licence_number"
                                        name="licence_number"
                                        defaultValue={
                                            driver?.licenceNumber ?? ''
                                        }
                                    />
                                </Field>
                                <Field
                                    id="licence_expires_on"
                                    label="Licence valid to"
                                    error={errors.licence_expires_on}
                                    hint="Trips are refused once it has expired."
                                >
                                    <Input
                                        id="licence_expires_on"
                                        name="licence_expires_on"
                                        type="date"
                                        defaultValue={
                                            driver?.licenceExpiresOn ?? ''
                                        }
                                        {...invalid(
                                            'licence_expires_on',
                                            errors.licence_expires_on,
                                        )}
                                    />
                                </Field>
                                {driver && (
                                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                        <input
                                            type="hidden"
                                            name="active"
                                            value="0"
                                        />
                                        <input
                                            type="checkbox"
                                            name="active"
                                            value="1"
                                            defaultChecked={driver.active}
                                        />
                                        Active: can be given trips
                                    </label>
                                )}
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {driver ? 'Save' : 'Add driver'}
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
