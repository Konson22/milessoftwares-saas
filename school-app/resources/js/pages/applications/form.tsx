import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { useSchool } from '@/lib/school';
import { index, store } from '@/routes/applications';
import type { Option } from '@/types';

type Props = { levels: Option[]; programmes: Option[] };

const GENDERS: Option[] = [
    { value: 'female', label: 'Female' },
    { value: 'male', label: 'Male' },
];

/**
 * Receiving an application for admission.
 */
export default function ApplicationForm({ levels, programmes }: Props) {
    const school = useSchool();
    const primary = school.value === 'primary';

    return (
        <>
            <Head title="Receive an application" />
            <PageHeader
                title="Receive an application"
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Admissions</Link>
                    </Button>
                }
            />

            <Panel className="max-w-3xl">
                <PanelBody>
                    <Form
                        {...store.form()}
                        className="grid gap-4 sm:grid-cols-2"
                    >
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
                                            placeholder="Choose a programme"
                                            options={programmes}
                                        />
                                    </Field>
                                )}
                                <Field
                                    id="level"
                                    label={
                                        primary
                                            ? 'Class applying for'
                                            : 'Year of entry'
                                    }
                                    error={errors.level}
                                >
                                    <Select
                                        id="level"
                                        name="level"
                                        required
                                        defaultValue="1"
                                        options={levels}
                                    />
                                </Field>
                                <Field
                                    id="previous_school"
                                    label="Previous school"
                                    error={errors.previous_school}
                                >
                                    <Input
                                        id="previous_school"
                                        name="previous_school"
                                    />
                                </Field>
                                <Field
                                    id="first_name"
                                    label="First name"
                                    error={errors.first_name}
                                >
                                    <Input
                                        id="first_name"
                                        name="first_name"
                                        required
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
                                        defaultValue=""
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
                                    />
                                </Field>
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
                                                {...invalid(
                                                    'email',
                                                    errors.email,
                                                )}
                                            />
                                        </Field>
                                    </>
                                )}
                                <Field
                                    id="guardian_name"
                                    label={
                                        primary
                                            ? 'Parent or guardian'
                                            : 'Next of kin'
                                    }
                                    error={errors.guardian_name}
                                >
                                    <Input
                                        id="guardian_name"
                                        name="guardian_name"
                                        required={primary}
                                        {...invalid(
                                            'guardian_name',
                                            errors.guardian_name,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="guardian_phone"
                                    label="Their phone"
                                    error={errors.guardian_phone}
                                >
                                    <Input
                                        id="guardian_phone"
                                        name="guardian_phone"
                                        type="tel"
                                    />
                                </Field>
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        Receive application
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
