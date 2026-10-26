import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { useSchool } from '@/lib/school';
import { index, show, store, update } from '@/routes/students';
import type { Option } from '@/types';

type Student = {
    id: string;
    number: string;
    firstName: string;
    lastName: string;
    gender: string;
    dateOfBirth: string | null;
    classId: string | null;
    status: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    guardianName: string | null;
    guardianPhone: string | null;
    guardianRelationship: string | null;
};

type Props = {
    student: Student | null;
    classes: Option[];
    classId: string | null;
    today: string;
};

const GENDERS: Option[] = [
    { value: 'female', label: 'Female' },
    { value: 'male', label: 'Male' },
];

const STATUSES: Option[] = [
    { value: 'active', label: 'Enrolled' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'withdrawn', label: 'Withdrawn' },
    { value: 'graduated', label: 'Graduated' },
];

/**
 * Enrolling a student, or changing their details, class and status.
 */
export default function StudentForm({
    student,
    classes,
    classId,
    today,
}: Props) {
    const school = useSchool();
    const primary = school.value === 'primary';
    const action = student ? update.form(student.id) : store.form();
    const title = student
        ? `${student.firstName} ${student.lastName}`
        : primary
          ? 'Enrol a pupil'
          : 'Enrol a student';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description={
                    student
                        ? `${school.studentNumber} ${student.number}`
                        : `A ${school.studentNumber.toLowerCase()} is given on enrolment.`
                }
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={student ? show(student.id) : index()}>
                            ←{' '}
                            {student ? title : primary ? 'Pupils' : 'Students'}
                        </Link>
                    </Button>
                }
            />

            <Form {...action} className="grid max-w-3xl gap-4">
                {({ errors, processing }) => (
                    <>
                        <Panel>
                            <PanelHeader title="Details" />
                            <PanelBody className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="first_name"
                                    label="First name"
                                    error={errors.first_name}
                                >
                                    <Input
                                        id="first_name"
                                        name="first_name"
                                        required
                                        defaultValue={student?.firstName}
                                        {...invalid(
                                            'first_name',
                                            errors.first_name,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="last_name"
                                    label="Last name"
                                    error={errors.last_name}
                                >
                                    <Input
                                        id="last_name"
                                        name="last_name"
                                        required
                                        defaultValue={student?.lastName}
                                        {...invalid(
                                            'last_name',
                                            errors.last_name,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="gender"
                                    label="Gender"
                                    error={errors.gender}
                                >
                                    <Select
                                        id="gender"
                                        name="gender"
                                        required
                                        defaultValue={student?.gender ?? ''}
                                        placeholder="Choose"
                                        options={GENDERS}
                                    />
                                </Field>
                                <Field
                                    id="date_of_birth"
                                    label="Date of birth"
                                    error={errors.date_of_birth}
                                >
                                    <Input
                                        id="date_of_birth"
                                        name="date_of_birth"
                                        type="date"
                                        max={today}
                                        defaultValue={
                                            student?.dateOfBirth ?? ''
                                        }
                                    />
                                </Field>
                                <Field
                                    id="school_class_id"
                                    label="Class"
                                    error={errors.school_class_id}
                                >
                                    <Select
                                        id="school_class_id"
                                        name="school_class_id"
                                        required
                                        defaultValue={
                                            student?.classId ?? classId ?? ''
                                        }
                                        placeholder="Choose a class"
                                        options={classes}
                                    />
                                </Field>
                                {student ? (
                                    <Field
                                        id="status"
                                        label="Status"
                                        error={errors.status}
                                    >
                                        <Select
                                            id="status"
                                            name="status"
                                            defaultValue={student.status}
                                            options={STATUSES}
                                        />
                                    </Field>
                                ) : (
                                    <Field
                                        id="admitted_on"
                                        label="Admitted"
                                        error={errors.admitted_on}
                                    >
                                        <Input
                                            id="admitted_on"
                                            name="admitted_on"
                                            type="date"
                                            required
                                            max={today}
                                            defaultValue={today}
                                        />
                                    </Field>
                                )}
                                {!primary && (
                                    <>
                                        <Field
                                            id="phone"
                                            label="Phone"
                                            error={errors.phone}
                                        >
                                            <Input
                                                id="phone"
                                                name="phone"
                                                type="tel"
                                                defaultValue={
                                                    student?.phone ?? ''
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id="email"
                                            label="Email"
                                            error={errors.email}
                                        >
                                            <Input
                                                id="email"
                                                name="email"
                                                type="email"
                                                defaultValue={
                                                    student?.email ?? ''
                                                }
                                                {...invalid(
                                                    'email',
                                                    errors.email,
                                                )}
                                            />
                                        </Field>
                                    </>
                                )}
                                <Field
                                    id="address"
                                    label="Home address"
                                    error={errors.address}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="address"
                                        name="address"
                                        defaultValue={student?.address ?? ''}
                                    />
                                </Field>
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader
                                title={
                                    primary
                                        ? 'Parent or guardian'
                                        : 'Next of kin'
                                }
                                description={
                                    primary
                                        ? 'Who the school contacts about the pupil.'
                                        : 'Optional: who to contact in an emergency.'
                                }
                            />
                            <PanelBody className="grid gap-4 sm:grid-cols-3">
                                <Field
                                    id="guardian_name"
                                    label="Name"
                                    error={errors.guardian_name}
                                >
                                    <Input
                                        id="guardian_name"
                                        name="guardian_name"
                                        required={primary}
                                        defaultValue={
                                            student?.guardianName ?? ''
                                        }
                                        {...invalid(
                                            'guardian_name',
                                            errors.guardian_name,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="guardian_phone"
                                    label="Phone"
                                    error={errors.guardian_phone}
                                >
                                    <Input
                                        id="guardian_phone"
                                        name="guardian_phone"
                                        type="tel"
                                        defaultValue={
                                            student?.guardianPhone ?? ''
                                        }
                                    />
                                </Field>
                                <Field
                                    id="guardian_relationship"
                                    label="Relationship"
                                    error={errors.guardian_relationship}
                                >
                                    <Input
                                        id="guardian_relationship"
                                        name="guardian_relationship"
                                        placeholder="Mother, uncle..."
                                        defaultValue={
                                            student?.guardianRelationship ?? ''
                                        }
                                    />
                                </Field>
                            </PanelBody>
                        </Panel>

                        <div>
                            <Button type="submit" disabled={processing}>
                                {student ? 'Save' : 'Enrol'}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
