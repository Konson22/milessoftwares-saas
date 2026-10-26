import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid } from '@/components/field';
import { useSchool } from '@/lib/school';
import { index, store, update } from '@/routes/subjects';

type Subject = {
    id: string;
    code: string;
    name: string;
    credits: number | null;
    active: boolean;
};

/**
 * Adding or changing a subject, module or course.
 */
export default function SubjectForm({ subject }: { subject: Subject | null }) {
    const school = useSchool();
    const word = school.subject;
    const action = subject ? update.form(subject.id) : store.form();
    const title = subject ? subject.name : `Add a ${word.toLowerCase()}`;

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>
                            ← {word === 'Course' ? 'Courses' : `${word}s`}
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
                                    id="code"
                                    label="Code"
                                    error={errors.code}
                                >
                                    <Input
                                        id="code"
                                        name="code"
                                        required
                                        placeholder={
                                            school.value === 'primary'
                                                ? 'MATH'
                                                : 'CS101'
                                        }
                                        defaultValue={subject?.code}
                                        {...invalid('code', errors.code)}
                                    />
                                </Field>
                                {school.usesCredits && (
                                    <Field
                                        id="credit_hours"
                                        label="Credit hours"
                                        error={errors.credit_hours}
                                    >
                                        <Input
                                            id="credit_hours"
                                            name="credit_hours"
                                            type="number"
                                            min={1}
                                            max={30}
                                            required
                                            defaultValue={subject?.credits ?? 3}
                                            {...invalid(
                                                'credit_hours',
                                                errors.credit_hours,
                                            )}
                                        />
                                    </Field>
                                )}
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
                                        defaultValue={subject?.name}
                                        {...invalid('name', errors.name)}
                                    />
                                </Field>
                                {subject && (
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
                                            defaultChecked={subject.active}
                                        />
                                        Taught: can be given to classes
                                    </label>
                                )}
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {subject
                                            ? 'Save'
                                            : `Add ${word.toLowerCase()}`}
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
