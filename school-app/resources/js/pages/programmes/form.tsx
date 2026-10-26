import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { index, store, update } from '@/routes/programmes';
import type { Option } from '@/types';

type Programme = {
    id: string;
    code: string;
    name: string;
    award: string;
    durationYears: number;
    departmentId: string | null;
    active: boolean;
};

type Props = {
    programme: Programme | null;
    awards: Option[];
    departments: Option[];
};

/**
 * Adding or changing a programme.
 */
export default function ProgrammeForm({
    programme,
    awards,
    departments,
}: Props) {
    const action = programme ? update.form(programme.id) : store.form();
    const title = programme ? programme.name : 'Add a programme';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Programmes</Link>
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
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        placeholder="Diploma in Nursing"
                                        defaultValue={programme?.name}
                                        {...invalid('name', errors.name)}
                                    />
                                </Field>
                                <Field
                                    id="code"
                                    label="Code"
                                    error={errors.code}
                                    hint="Short, used in class names (DIP-NUR Year 1)."
                                >
                                    <Input
                                        id="code"
                                        name="code"
                                        required
                                        placeholder="DIP-NUR"
                                        defaultValue={programme?.code}
                                        {...invalid('code', errors.code)}
                                    />
                                </Field>
                                <Field
                                    id="award"
                                    label="Award"
                                    error={errors.award}
                                >
                                    <Select
                                        id="award"
                                        name="award"
                                        required
                                        defaultValue={programme?.award}
                                        options={awards}
                                    />
                                </Field>
                                <Field
                                    id="duration_years"
                                    label="Length in years"
                                    error={errors.duration_years}
                                >
                                    <Input
                                        id="duration_years"
                                        name="duration_years"
                                        type="number"
                                        min={1}
                                        max={7}
                                        required
                                        defaultValue={
                                            programme?.durationYears ?? 3
                                        }
                                        {...invalid(
                                            'duration_years',
                                            errors.duration_years,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="department_id"
                                    label="Department"
                                    error={errors.department_id}
                                >
                                    <Select
                                        id="department_id"
                                        name="department_id"
                                        defaultValue={
                                            programme?.departmentId ?? ''
                                        }
                                        placeholder="None"
                                        options={departments}
                                    />
                                </Field>
                                {programme ? (
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
                                            defaultChecked={programme.active}
                                        />
                                        Open: takes new applications
                                    </label>
                                ) : (
                                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                        <input
                                            type="hidden"
                                            name="with_classes"
                                            value="0"
                                        />
                                        <input
                                            type="checkbox"
                                            name="with_classes"
                                            value="1"
                                            defaultChecked
                                        />
                                        Add a class for each year of study
                                    </label>
                                )}
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {programme ? 'Save' : 'Add programme'}
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
