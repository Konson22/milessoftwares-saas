import { Form, Head, Link } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Select } from '@/components/field';
import { ordinal, score } from '@/lib/results';
import type { AssessmentColumn, StudentResult } from '@/lib/results';
import { useSchool } from '@/lib/school';
import { show } from '@/routes/results';
import { show as showStudent } from '@/routes/students';
import type { GradeBand, Option } from '@/types';

type Props = {
    schoolName: string | null;
    student: {
        id: string;
        number: string;
        name: string;
        class: string | null;
        programme: string | null;
        classTeacher: string | null;
    };
    term: { id: string; title: string } | null;
    terms: Option[];
    assessments: AssessmentColumn[];
    report: StudentResult | null;
    classSize: number | null;
    cgpa: number | null;
    attendance: { present: number; absent: number; excused: number };
    grading: GradeBand[];
};

/**
 * A student's report card for a term, ready to print.
 */
export default function ReportCard({
    schoolName,
    student,
    term,
    terms,
    assessments,
    report,
    classSize,
    cgpa,
    attendance,
    grading,
}: Props) {
    const school = useSchool();
    const subjects = report?.subjects ?? [];

    return (
        <>
            <Head title={`Report: ${student.name}`} />
            <div className="print:hidden">
                <PageHeader
                    title="Report card"
                    description={student.name}
                    actions={
                        <>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => window.print()}
                            >
                                <Printer className="size-4" aria-hidden />
                                Print
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={showStudent(student.id)}>
                                    ← {student.name}
                                </Link>
                            </Button>
                        </>
                    }
                />
            </div>

            <Panel className="max-w-4xl">
                <PanelToolbar className="print:hidden">
                    <Form {...show.form(student.id)} className="flex gap-2">
                        <Select
                            name="term"
                            aria-label={school.period}
                            defaultValue={term?.id ?? ''}
                            options={terms}
                            className="w-auto"
                        />
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>

                <PanelBody className="grid gap-4">
                    <div className="flex flex-wrap items-start justify-between gap-4 border-b pb-3">
                        <div>
                            <p className="text-lg font-semibold">
                                {schoolName}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {school.value === 'primary'
                                    ? 'Pupil’s report'
                                    : 'Statement of results'}{' '}
                                · {term?.title}
                            </p>
                        </div>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 text-sm">
                            <dt className="text-muted-foreground">Name</dt>
                            <dd className="font-medium">{student.name}</dd>
                            <dt className="text-muted-foreground">
                                {school.studentNumber}
                            </dt>
                            <dd>{student.number}</dd>
                            {student.programme && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Programme
                                    </dt>
                                    <dd>{student.programme}</dd>
                                </>
                            )}
                            <dt className="text-muted-foreground">Class</dt>
                            <dd>{student.class ?? '—'}</dd>
                        </dl>
                    </div>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{school.subject}</TableHead>
                                {school.usesCredits && (
                                    <TableHead className="text-right">
                                        Credits
                                    </TableHead>
                                )}
                                {assessments.map((assessment) => (
                                    <TableHead
                                        key={assessment.id}
                                        className="text-right"
                                    >
                                        {assessment.name}{' '}
                                        <span className="font-normal text-muted-foreground">
                                            ({assessment.weight}%)
                                        </span>
                                    </TableHead>
                                ))}
                                <TableHead className="text-right">
                                    Mark
                                </TableHead>
                                <TableHead>Grade</TableHead>
                                {school.usesCredits && (
                                    <TableHead className="text-right">
                                        Points
                                    </TableHead>
                                )}
                                <TableHead>Remark</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {subjects.length === 0 && (
                                <TableEmpty colSpan={assessments.length + 6}>
                                    No marks yet.
                                </TableEmpty>
                            )}
                            {subjects.map((subject) => (
                                <TableRow key={subject.subjectId}>
                                    <TableCell>
                                        <span className="font-medium">
                                            {subject.name}
                                        </span>
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            {subject.code}
                                        </span>
                                    </TableCell>
                                    {school.usesCredits && (
                                        <TableCell className="text-right tabular-nums">
                                            {subject.credits}
                                        </TableCell>
                                    )}
                                    {assessments.map((assessment) => (
                                        <TableCell
                                            key={assessment.id}
                                            className="text-right tabular-nums"
                                        >
                                            {score(
                                                subject.scores[assessment.id] ??
                                                    null,
                                            )}
                                        </TableCell>
                                    ))}
                                    <TableCell className="text-right font-semibold tabular-nums">
                                        {score(subject.mark)}
                                        {subject.mark !== null &&
                                            !subject.complete && (
                                                <span className="text-muted-foreground">
                                                    *
                                                </span>
                                            )}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {subject.grade ?? '—'}
                                    </TableCell>
                                    {school.usesCredits && (
                                        <TableCell className="text-right tabular-nums">
                                            {subject.points?.toFixed(1) ?? '—'}
                                        </TableCell>
                                    )}
                                    <TableCell className="text-muted-foreground">
                                        {subject.remark}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        {report && (
                            <TableFooter>
                                <TableRow>
                                    <TableCell
                                        colSpan={
                                            assessments.length +
                                            (school.usesCredits ? 6 : 4)
                                        }
                                    >
                                        <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                                            {school.usesCredits ? (
                                                <>
                                                    <span>
                                                        GPA{' '}
                                                        <strong className="tabular-nums">
                                                            {report.gpa?.toFixed(
                                                                2,
                                                            ) ?? '—'}
                                                        </strong>
                                                    </span>
                                                    <span>
                                                        CGPA{' '}
                                                        <strong className="tabular-nums">
                                                            {cgpa?.toFixed(2) ??
                                                                '—'}
                                                        </strong>
                                                    </span>
                                                    <span>
                                                        Credits{' '}
                                                        <strong className="tabular-nums">
                                                            {report.credits}
                                                        </strong>
                                                    </span>
                                                </>
                                            ) : (
                                                <>
                                                    <span>
                                                        Total{' '}
                                                        <strong className="tabular-nums">
                                                            {score(
                                                                report.total,
                                                            )}
                                                        </strong>
                                                    </span>
                                                    <span>
                                                        Average{' '}
                                                        <strong className="tabular-nums">
                                                            {score(
                                                                report.average,
                                                            )}
                                                        </strong>
                                                    </span>
                                                </>
                                            )}
                                            {school.ranksInClass &&
                                                report.position !== null && (
                                                    <span>
                                                        Position{' '}
                                                        <strong>
                                                            {ordinal(
                                                                report.position,
                                                            )}
                                                            {classSize !==
                                                                null &&
                                                                ` of ${classSize}`}
                                                        </strong>
                                                    </span>
                                                )}
                                            <span>
                                                Attendance{' '}
                                                <strong className="tabular-nums">
                                                    {attendance.present}
                                                </strong>{' '}
                                                present,{' '}
                                                <strong className="tabular-nums">
                                                    {attendance.absent}
                                                </strong>{' '}
                                                absent
                                                {attendance.excused > 0 &&
                                                    `, ${attendance.excused} excused`}
                                            </span>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        )}
                    </Table>

                    <p className="text-xs text-muted-foreground">
                        Grades:{' '}
                        {grading
                            .map((band) => `${band.grade} ${band.min}+`)
                            .join(' · ')}
                        {subjects.some(
                            (subject) =>
                                subject.mark !== null && !subject.complete,
                        ) && ' · * not every assessment marked yet'}
                    </p>

                    <div className="mt-6 grid gap-8 text-sm sm:grid-cols-2">
                        <div className="border-t pt-1 text-muted-foreground">
                            {school.value === 'primary'
                                ? 'Class teacher'
                                : 'Head of department'}
                            {student.classTeacher &&
                                school.value === 'primary' &&
                                `: ${student.classTeacher}`}
                        </div>
                        <div className="border-t pt-1 text-muted-foreground">
                            {school.value === 'primary'
                                ? 'Head teacher'
                                : 'Registrar'}
                        </div>
                    </div>
                </PanelBody>
            </Panel>
        </>
    );
}
