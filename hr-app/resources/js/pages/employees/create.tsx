import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { AssignmentFields, SelectField, TextField } from '@/components/fields';
import type { Option, StructureOptions } from '@/components/fields';
import { index, store } from '@/routes/employees';

type Props = StructureOptions & {
    managers: { id: string; name: string }[];
    employmentTypes: Option[];
};

export default function CreateEmployee({
    branches,
    departments,
    positions,
    managers,
    employmentTypes,
}: Props) {
    return (
        <>
            <Head title="Add employee" />
            <PageHeader
                title="Add employee"
                description="Work details and the first contract. Personal and bank details are kept separately by HR managers."
            />

            <Form {...store.form()} className="grid gap-3">
                {({ errors, processing }) => (
                    <>
                        <Panel>
                            <PanelHeader title="Who" />
                            <PanelBody className="grid gap-3 sm:grid-cols-4">
                                <TextField
                                    name="first_name"
                                    label="First name"
                                    required
                                    error={errors.first_name}
                                />
                                <TextField
                                    name="middle_name"
                                    label="Middle name"
                                    error={errors.middle_name}
                                />
                                <TextField
                                    name="last_name"
                                    label="Last name"
                                    required
                                    error={errors.last_name}
                                />
                                <TextField
                                    name="preferred_name"
                                    label="Known as"
                                    error={errors.preferred_name}
                                />
                                <TextField
                                    name="work_email"
                                    label="Work email"
                                    type="email"
                                    error={errors.work_email}
                                />
                                <TextField
                                    name="work_phone"
                                    label="Work phone"
                                    error={errors.work_phone}
                                />
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader title="Where they work" />
                            <PanelBody className="grid gap-3 sm:grid-cols-4">
                                <AssignmentFields
                                    options={{
                                        branches,
                                        departments,
                                        positions,
                                    }}
                                    errors={errors}
                                />
                                <SelectField
                                    name="manager_id"
                                    label="Reports to"
                                    empty="Nobody"
                                    options={managers.map((manager) => ({
                                        value: manager.id,
                                        label: manager.name,
                                    }))}
                                    error={errors.manager_id}
                                />
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader title="Contract" />
                            <PanelBody className="grid gap-3 sm:grid-cols-4">
                                <TextField
                                    name="hired_on"
                                    label="Starts on"
                                    type="date"
                                    required
                                    error={errors.hired_on}
                                />
                                <SelectField
                                    name="contract_type"
                                    label="Type"
                                    options={employmentTypes}
                                    defaultValue="permanent"
                                    error={errors.contract_type}
                                />
                                <TextField
                                    name="ends_on"
                                    label="Ends on (fixed term)"
                                    type="date"
                                    error={errors.ends_on}
                                />
                                <TextField
                                    name="probation_ends_on"
                                    label="Probation ends"
                                    type="date"
                                    error={errors.probation_ends_on}
                                />
                                <TextField
                                    name="notice_days"
                                    label="Notice (days)"
                                    type="number"
                                    error={errors.notice_days}
                                />
                            </PanelBody>
                        </Panel>

                        <div className="flex gap-2">
                            <Button type="submit" disabled={processing}>
                                Add employee
                            </Button>
                            <Button asChild variant="ghost">
                                <Link href={index()}>Cancel</Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
