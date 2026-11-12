import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { AssignmentFields, SelectField, TextField } from '@/components/payroll/fields';
import type { StructureOptions } from '@/components/payroll/fields';
import { PAY_BASES } from '@/lib/payroll/pay';
import { index, store } from '@/routes/payroll/employees';

export default function CreateEmployee(options: StructureOptions) {
    return (
        <>
            <Head title="Add person" />
            <PageHeader title="Add person" />

            <Form {...store.form()} className="grid gap-3">
                {({ errors, processing }) => (
                    <>
                        <Panel>
                            <PanelHeader title="Who" />
                            <PanelBody className="grid gap-3 sm:grid-cols-3">
                                <TextField
                                    name="first_name"
                                    label="First name"
                                    required
                                    error={errors.first_name}
                                />
                                <TextField
                                    name="last_name"
                                    label="Last name"
                                    required
                                    error={errors.last_name}
                                />
                                <TextField
                                    name="work_email"
                                    label="Work email"
                                    type="email"
                                    error={errors.work_email}
                                />
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader title="Work" />
                            <PanelBody className="grid gap-3 sm:grid-cols-3">
                                <AssignmentFields
                                    options={options}
                                    errors={errors}
                                />
                                <TextField
                                    name="hired_on"
                                    label="Started on"
                                    type="date"
                                    required
                                    error={errors.hired_on}
                                />
                                <SelectField
                                    name="pay_basis"
                                    label="Paid"
                                    options={PAY_BASES}
                                    defaultValue="monthly"
                                    error={errors.pay_basis}
                                />
                            </PanelBody>
                        </Panel>

                        <div className="flex gap-2">
                            <Button type="submit" disabled={processing}>
                                Add person
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
