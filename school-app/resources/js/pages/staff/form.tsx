import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { useSchool } from '@/lib/school';
import { index, store, update } from '@/routes/staff';
import type { Option } from '@/types';

type Member = {
    id: string;
    number: string;
    name: string;
    position: string;
    departmentId: string | null;
    phone: string | null;
    email: string | null;
    startedOn: string;
    active: boolean;
};

type Props = {
    member: Member | null;
    positions: string[];
    departments: Option[];
    today: string;
};

/**
 * Adding or changing a member of staff.
 */
export default function StaffForm({
    member,
    positions,
    departments,
    today,
}: Props) {
    const school = useSchool();
    const action = member ? update.form(member.id) : store.form();
    const title = member ? member.name : 'Add staff';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description={
                    member
                        ? member.number
                        : 'HR is told of each new member of staff.'
                }
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Staff</Link>
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
                                    label="Full name"
                                    error={errors.name}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        defaultValue={member?.name}
                                        {...invalid('name', errors.name)}
                                    />
                                </Field>
                                <Field
                                    id="position"
                                    label="Position"
                                    error={errors.position}
                                >
                                    <Input
                                        id="position"
                                        name="position"
                                        list="positions"
                                        required
                                        defaultValue={
                                            member?.position ?? school.staff
                                        }
                                        {...invalid(
                                            'position',
                                            errors.position,
                                        )}
                                    />
                                    <datalist id="positions">
                                        {positions.map((position) => (
                                            <option
                                                key={position}
                                                value={position}
                                            />
                                        ))}
                                    </datalist>
                                </Field>
                                {school.usesDepartments ? (
                                    <Field
                                        id="department_id"
                                        label="Department"
                                        error={errors.department_id}
                                    >
                                        <Select
                                            id="department_id"
                                            name="department_id"
                                            defaultValue={
                                                member?.departmentId ?? ''
                                            }
                                            placeholder="None"
                                            options={departments}
                                        />
                                    </Field>
                                ) : (
                                    <div />
                                )}
                                <Field
                                    id="phone"
                                    label="Phone"
                                    error={errors.phone}
                                >
                                    <Input
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        defaultValue={member?.phone ?? ''}
                                    />
                                </Field>
                                <Field
                                    id="email"
                                    label="Work email"
                                    error={errors.email}
                                >
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue={member?.email ?? ''}
                                        {...invalid('email', errors.email)}
                                    />
                                </Field>
                                {!member && (
                                    <Field
                                        id="started_on"
                                        label="Started"
                                        error={errors.started_on}
                                    >
                                        <Input
                                            id="started_on"
                                            name="started_on"
                                            type="date"
                                            max={today}
                                            defaultValue={today}
                                        />
                                    </Field>
                                )}
                                {member && (
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
                                            defaultChecked={member.active}
                                        />
                                        Works here: can be given classes to
                                        teach
                                    </label>
                                )}
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {member ? 'Save' : 'Add staff'}
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
