import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { useSchool } from '@/lib/school';
import { index, show, store, update } from '@/routes/classes';
import type { Option } from '@/types';

type SchoolClass = {
    id: string;
    name: string;
    level: number;
    programmeId: string | null;
    classTeacherId: string | null;
    capacity: number | null;
    active: boolean;
};

type Props = {
    schoolClass: SchoolClass | null;
    levels: Option[];
    programmes: Option[];
    staff: Option[];
};

/**
 * Adding or changing a class.
 */
export default function ClassForm({
    schoolClass,
    levels,
    programmes,
    staff,
}: Props) {
    const school = useSchool();
    const action = schoolClass ? update.form(schoolClass.id) : store.form();
    const title = schoolClass ? schoolClass.name : 'Add a class';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={schoolClass ? show(schoolClass.id) : index()}
                        >
                            ← {schoolClass ? schoolClass.name : 'Classes'}
                        </Link>
                    </Button>
                }
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-2">
                        {({ errors, processing }) => (
                            <>
                                {school.usesProgrammes && (
                                    <Field
                                        id="programme_id"
                                        label="Programme"
                                        error={errors.programme_id}
                                        className="sm:col-span-2"
                                    >
                                        <Select
                                            id="programme_id"
                                            name="programme_id"
                                            required
                                            defaultValue={
                                                schoolClass?.programmeId ?? ''
                                            }
                                            placeholder="Choose a programme"
                                            options={programmes}
                                        />
                                    </Field>
                                )}
                                <Field
                                    id="name"
                                    label="Name"
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        placeholder={
                                            school.usesProgrammes
                                                ? 'DIP-NUR Year 1'
                                                : 'Primary 4 East'
                                        }
                                        defaultValue={schoolClass?.name}
                                        {...invalid('name', errors.name)}
                                    />
                                </Field>
                                <Field
                                    id="level"
                                    label={school.level}
                                    error={errors.level}
                                >
                                    <Select
                                        id="level"
                                        name="level"
                                        required
                                        defaultValue={
                                            schoolClass
                                                ? String(schoolClass.level)
                                                : '1'
                                        }
                                        options={levels}
                                    />
                                </Field>
                                <Field
                                    id="class_teacher_id"
                                    label="Class teacher"
                                    error={errors.teacher_id}
                                >
                                    <Select
                                        id="class_teacher_id"
                                        name="class_teacher_id"
                                        defaultValue={
                                            schoolClass?.classTeacherId ?? ''
                                        }
                                        placeholder="None"
                                        options={staff}
                                    />
                                </Field>
                                <Field
                                    id="capacity"
                                    label="Places"
                                    error={errors.capacity}
                                    hint="Leave blank for no limit."
                                >
                                    <Input
                                        id="capacity"
                                        name="capacity"
                                        type="number"
                                        min={1}
                                        defaultValue={
                                            schoolClass?.capacity ?? ''
                                        }
                                        {...invalid(
                                            'capacity',
                                            errors.capacity,
                                        )}
                                    />
                                </Field>
                                {schoolClass && (
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
                                            defaultChecked={schoolClass.active}
                                        />
                                        Open: takes new students
                                    </label>
                                )}
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {schoolClass ? 'Save' : 'Add class'}
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
