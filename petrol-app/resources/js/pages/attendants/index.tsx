import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import { store, update } from '@/routes/attendants';

type Attendant = {
    id: string;
    name: string;
    employeeCode: string | null;
    phone: string | null;
    userId: string | null;
    active: boolean;
    atStation: boolean;
};

export default function Attendants({
    attendants,
    users,
    canManage,
}: {
    attendants: Attendant[];
    users: { id: string; name: string; email: string }[];
    canManage: boolean;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Attendants" />
            <PageHeader
                title="Attendants"
                description="Link an attendant to a sign-in when they sell as themselves."
            />
            <Panel>
                <PanelHeader title="Roster" />
                <PanelBody className="grid gap-3">
                    <ul className="text-sm">
                        {attendants.map((attendant) => (
                            <li key={attendant.id}>
                                {attendant.employeeCode} {attendant.name}
                                {attendant.atStation
                                    ? ''
                                    : ' (not at this station)'}
                                {attendant.active ? '' : ' (inactive)'}
                            </li>
                        ))}
                    </ul>
                    {canManage && (
                        <Form
                            {...store.form()}
                            className="grid gap-3 md:grid-cols-3"
                        >
                            <Field id="name" label="Name" error={errors.name}>
                                <Input id="name" name="name" required />
                            </Field>
                            <Field
                                id="employee_code"
                                label="Employee code"
                                error={errors.employee_code}
                            >
                                <Input
                                    id="employee_code"
                                    name="employee_code"
                                    required
                                />
                            </Field>
                            <Field id="phone" label="Phone">
                                <Input id="phone" name="phone" />
                            </Field>
                            <Field id="user_id" label="Sign-in">
                                <Select
                                    id="user_id"
                                    name="user_id"
                                    placeholder="None"
                                    options={users.map((user) => ({
                                        value: user.id,
                                        label: user.name,
                                    }))}
                                />
                            </Field>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="hidden"
                                    name="at_station"
                                    value="0"
                                />
                                <input
                                    type="checkbox"
                                    name="at_station"
                                    value="1"
                                    defaultChecked
                                />
                                Works at this station
                            </label>
                            <div className="self-end">
                                <Button type="submit">Add attendant</Button>
                            </div>
                        </Form>
                    )}
                    {canManage &&
                        attendants.map((attendant) => (
                            <Form
                                key={attendant.id}
                                {...update.form(attendant.id)}
                                className="flex flex-wrap items-end gap-2"
                            >
                                <Input
                                    name="name"
                                    defaultValue={attendant.name}
                                    aria-label="Name"
                                    required
                                />
                                <Input
                                    name="employee_code"
                                    defaultValue={attendant.employeeCode ?? ''}
                                    aria-label="Code"
                                    required
                                />
                                <input type="hidden" name="active" value="0" />
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="active"
                                        value="1"
                                        defaultChecked={attendant.active}
                                    />
                                    Active
                                </label>
                                <input
                                    type="hidden"
                                    name="at_station"
                                    value="0"
                                />
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="at_station"
                                        value="1"
                                        defaultChecked={attendant.atStation}
                                    />
                                    At this station
                                </label>
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                >
                                    Save
                                </Button>
                            </Form>
                        ))}
                </PanelBody>
            </Panel>
        </>
    );
}
