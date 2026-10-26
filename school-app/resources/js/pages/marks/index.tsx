import { Form, Head } from '@inertiajs/react';
import { PenLine } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, Select } from '@/components/field';
import { UnlinkedNotice } from '@/components/unlinked-notice';
import { useSchool } from '@/lib/school';
import { index, store } from '@/routes/marks';
import type { Option } from '@/types';

type Props = {
    filters: {
        term: string | null;
        assessment: string | null;
        class: string;
        subject: string | null;
    };
    terms: Option[];
    assessments: Option[];
    classes: Option[];
    subjects: Option[];
    sheet: {
        title: string;
        students: { id: string; number: string; name: string; score: string }[];
    } | null;
    canRecord: boolean;
    linked: boolean;
};

/**
 * Entering marks out of 100: a term's assessment, a class and a subject.
 */
export default function Marks({
    filters,
    terms,
    assessments,
    classes,
    subjects,
    sheet,
    canRecord,
    linked,
}: Props) {
    const school = useSchool();
    const word = school.subject.toLowerCase();

    return (
        <>
            <Head title="Marks" />
            <PageHeader
                title="Marks"
                description="Each student's score out of 100. A blank removes a mark entered before."
            />

            {!linked && <UnlinkedNotice what="enter marks for" />}

            <Panel>
                <PanelToolbar>
                    <Form
                        {...index.form()}
                        className="flex flex-wrap items-end gap-2"
                    >
                        <Field id="term" label={school.period}>
                            <Select
                                id="term"
                                name="term"
                                defaultValue={filters.term ?? ''}
                                options={terms}
                                className="w-auto"
                            />
                        </Field>
                        <Field id="assessment" label="Assessment">
                            <Select
                                id="assessment"
                                name="assessment"
                                defaultValue={filters.assessment ?? ''}
                                options={assessments}
                                className="w-auto"
                            />
                        </Field>
                        <Field id="class" label="Class">
                            <Select
                                id="class"
                                name="class"
                                defaultValue={filters.class}
                                placeholder="Choose a class"
                                options={classes}
                                className="w-auto"
                            />
                        </Field>
                        {subjects.length > 0 && (
                            <Field id="subject" label={school.subject}>
                                <Select
                                    id="subject"
                                    name="subject"
                                    defaultValue={filters.subject ?? ''}
                                    placeholder={`Choose a ${word}`}
                                    options={subjects}
                                    className="w-auto"
                                />
                            </Field>
                        )}
                        <Button type="submit" variant="outline">
                            Open mark sheet
                        </Button>
                    </Form>
                </PanelToolbar>

                {sheet === null ? (
                    <EmptyState icon={PenLine} title="Choose a mark sheet">
                        {assessments.length === 0
                            ? `This ${school.period.toLowerCase()} has no assessments: add them in Calendar & settings.`
                            : filters.class === ''
                              ? 'Choose a class, then open the sheet.'
                              : `Now choose the ${word}.`}
                    </EmptyState>
                ) : (
                    <Form {...store.form()} options={{ preserveScroll: true }}>
                        {({ errors, processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="assessment_id"
                                    value={filters.assessment ?? ''}
                                />
                                <input
                                    type="hidden"
                                    name="school_class_id"
                                    value={filters.class}
                                />
                                <input
                                    type="hidden"
                                    name="subject_id"
                                    value={filters.subject ?? ''}
                                />
                                <PanelHeader title={sheet.title} />
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {school.studentNumber}
                                            </TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead className="w-36">
                                                Mark / 100
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {sheet.students.length === 0 && (
                                            <TableEmpty colSpan={3}>
                                                No students in this class.
                                            </TableEmpty>
                                        )}
                                        {sheet.students.map((student) => {
                                            const error = (
                                                errors as Record<
                                                    string,
                                                    string | undefined
                                                >
                                            )[`scores.${student.id}`];

                                            return (
                                                <TableRow key={student.id}>
                                                    <TableCell className="font-mono text-xs">
                                                        {student.number}
                                                    </TableCell>
                                                    <TableCell className="font-medium">
                                                        {student.name}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Input
                                                            name={`scores[${student.id}]`}
                                                            inputMode="decimal"
                                                            defaultValue={
                                                                student.score
                                                            }
                                                            disabled={
                                                                !canRecord
                                                            }
                                                            aria-label={`Mark of ${student.name}`}
                                                            aria-invalid={
                                                                error
                                                                    ? true
                                                                    : undefined
                                                            }
                                                            className="w-24 text-right tabular-nums"
                                                        />
                                                        {error && (
                                                            <p className="mt-1 text-xs text-destructive">
                                                                {error}
                                                            </p>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                                {canRecord && sheet.students.length > 0 && (
                                    <div className="flex items-center gap-3 border-t px-3 py-3">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save marks
                                        </Button>
                                        {(errors.scores ||
                                            errors.subject_id) && (
                                            <span className="text-sm text-destructive">
                                                {errors.scores ??
                                                    errors.subject_id}
                                            </span>
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                    </Form>
                )}
            </Panel>
        </>
    );
}
