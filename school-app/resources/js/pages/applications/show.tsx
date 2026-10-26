import { Form, Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import { Status } from '@/components/status';
import { formatDate, formatDateTime } from '@/lib/status';
import { admit, decline, index } from '@/routes/applications';
import { show as showStudent } from '@/routes/students';
import type { Option } from '@/types';

type Props = {
    application: {
        id: string;
        number: string;
        name: string;
        gender: string;
        dateOfBirth: string | null;
        phone: string | null;
        email: string | null;
        guardianName: string | null;
        guardianPhone: string | null;
        previousSchool: string | null;
        applyingFor: string;
        status: string;
        receivedOn: string | null;
        decidedBy: string | null;
        decidedAt: string | null;
        decisionNote: string | null;
        student: { id: string; number: string } | null;
    };
    classes: Option[];
    canDecide: boolean;
};

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children || '—'}</dd>
        </div>
    );
}

/**
 * An application: the applicant, and admitting or declining them.
 */
export default function ApplicationShow({
    application,
    classes,
    canDecide,
}: Props) {
    const pending = application.status === 'pending';

    return (
        <>
            <Head title={`Application ${application.number}`} />
            <PageHeader
                title={application.name}
                description={`Application ${application.number} · ${application.applyingFor}${application.receivedOn ? ` · received ${formatDate(application.receivedOn)}` : ''}`}
                badges={<Status status={application.status} />}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Admissions</Link>
                    </Button>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
                <Panel>
                    <PanelHeader title="Applicant" />
                    <PanelBody>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Detail label="Gender">
                                <span className="capitalize">
                                    {application.gender}
                                </span>
                            </Detail>
                            <Detail label="Date of birth">
                                {application.dateOfBirth &&
                                    formatDate(application.dateOfBirth)}
                            </Detail>
                            <Detail label="Phone">{application.phone}</Detail>
                            <Detail label="Email">{application.email}</Detail>
                            <Detail label="Parent, guardian or next of kin">
                                {application.guardianName}
                            </Detail>
                            <Detail label="Their phone">
                                {application.guardianPhone}
                            </Detail>
                            <Detail label="Previous school">
                                {application.previousSchool}
                            </Detail>
                        </dl>
                    </PanelBody>
                </Panel>

                <div className="grid content-start gap-4">
                    {!pending && (
                        <Panel>
                            <PanelHeader title="Decision" />
                            <PanelBody className="grid gap-1 text-sm">
                                <p>
                                    {application.status === 'admitted'
                                        ? 'Admitted'
                                        : 'Declined'}
                                    {application.decidedBy &&
                                        ` by ${application.decidedBy}`}
                                    {application.decidedAt &&
                                        `, ${formatDateTime(application.decidedAt)}`}
                                    .
                                </p>
                                {application.decisionNote && (
                                    <p className="text-muted-foreground">
                                        {application.decisionNote}
                                    </p>
                                )}
                                {application.student && (
                                    <Link
                                        href={showStudent(
                                            application.student.id,
                                        )}
                                        className="text-primary hover:underline"
                                    >
                                        Student {application.student.number}
                                    </Link>
                                )}
                            </PanelBody>
                        </Panel>
                    )}

                    {pending && canDecide && (
                        <>
                            <Panel>
                                <PanelHeader
                                    title="Admit"
                                    description="Enrols the applicant in the class you choose."
                                />
                                <PanelBody>
                                    <Form
                                        {...admit.form(application.id)}
                                        className="grid gap-3"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <Field
                                                    id="school_class_id"
                                                    label="Class"
                                                    error={
                                                        errors.school_class_id ??
                                                        errors.application
                                                    }
                                                >
                                                    <Select
                                                        id="school_class_id"
                                                        name="school_class_id"
                                                        required
                                                        defaultValue={
                                                            classes[0]?.value ??
                                                            ''
                                                        }
                                                        options={classes}
                                                    />
                                                </Field>
                                                <div>
                                                    <Button
                                                        type="submit"
                                                        disabled={
                                                            processing ||
                                                            classes.length === 0
                                                        }
                                                    >
                                                        Admit
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                </PanelBody>
                            </Panel>
                            <Panel>
                                <PanelHeader title="Decline" />
                                <PanelBody>
                                    <Form
                                        {...decline.form(application.id)}
                                        className="grid gap-3"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <Field
                                                    id="note"
                                                    label="Reason"
                                                    error={errors.note}
                                                >
                                                    <Input
                                                        id="note"
                                                        name="note"
                                                        required
                                                        placeholder="No places left in Primary 1"
                                                    />
                                                </Field>
                                                <div>
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        disabled={processing}
                                                    >
                                                        Decline
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                </PanelBody>
                            </Panel>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
