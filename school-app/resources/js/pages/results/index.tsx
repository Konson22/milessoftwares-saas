import { Form, Head, Link } from '@inertiajs/react';
import { FileSpreadsheet, Printer } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
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
import { ordinal, score } from '@/lib/results';
import type { AssessmentColumn, StudentResult } from '@/lib/results';
import { useSchool } from '@/lib/school';
import { index, show } from '@/routes/results';
import type { GradeBand, Option } from '@/types';

type Props = {
    filters: { term: string | null; class: string };
    terms: Option[];
    classes: Option[];
    assessments: AssessmentColumn[];
    results: StudentResult[] | null;
    grading: GradeBand[];
};

/**
 * A class's term results: every student's mark and grade in each subject,
 * with their average and position (primary) or GPA.
 */
export default function Results({ filters, terms, classes, results }: Props) {
    const school = useSchool();
    const subjects = results?.[0]?.subjects ?? [];
    const term = terms.find((option) => option.value === filters.term)?.label;

    return (
        <>
            <Head title="Results" />
            <PageHeader
                title="Results"
                description={`Weighted marks out of 100 per ${school.subject.toLowerCase()}, graded the ${school.label.toLowerCase()} way.`}
                actions={
                    results && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => window.print()}
                        >
                            <Printer className="size-4" aria-hidden />
                            Print
                        </Button>
                    )
                }
            />

            <Panel>
                <PanelToolbar className="print:hidden">
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
                        <Button type="submit" variant="outline">
                            Show results
                        </Button>
                    </Form>
                </PanelToolbar>

                {results === null ? (
                    <EmptyState icon={FileSpreadsheet} title="Choose a class" />
                ) : (
                    <>
                        <p className="hidden px-3 py-2 text-sm font-medium print:block">
                            {
                                classes.find(
                                    (option) => option.value === filters.class,
                                )?.label
                            }{' '}
                            · {term}
                        </p>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        {school.ranksInClass && (
                                            <TableHead className="text-right">
                                                Pos.
                                            </TableHead>
                                        )}
                                        <TableHead>Student</TableHead>
                                        {subjects.map((subject) => (
                                            <TableHead
                                                key={subject.subjectId}
                                                className="text-right"
                                                title={subject.name}
                                            >
                                                {subject.code}
                                            </TableHead>
                                        ))}
                                        {school.usesCredits ? (
                                            <TableHead className="text-right">
                                                GPA
                                            </TableHead>
                                        ) : (
                                            <>
                                                <TableHead className="text-right">
                                                    Total
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Average
                                                </TableHead>
                                            </>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {results.length === 0 && (
                                        <TableEmpty
                                            colSpan={subjects.length + 4}
                                        >
                                            No students in this class.
                                        </TableEmpty>
                                    )}
                                    {results.map((result) => (
                                        <TableRow key={result.studentId}>
                                            {school.ranksInClass && (
                                                <TableCell className="text-right tabular-nums">
                                                    {result.position
                                                        ? ordinal(
                                                              result.position,
                                                          )
                                                        : '—'}
                                                </TableCell>
                                            )}
                                            <TableCell>
                                                <Link
                                                    href={show(
                                                        result.studentId,
                                                        {
                                                            query: {
                                                                term:
                                                                    filters.term ??
                                                                    '',
                                                            },
                                                        },
                                                    )}
                                                    className="font-medium hover:underline"
                                                >
                                                    {result.name}
                                                </Link>
                                                <span className="ml-2 font-mono text-xs text-muted-foreground">
                                                    {result.number}
                                                </span>
                                            </TableCell>
                                            {result.subjects.map((subject) => (
                                                <TableCell
                                                    key={subject.subjectId}
                                                    className={`text-right tabular-nums ${subject.complete ? '' : 'text-muted-foreground'}`}
                                                    title={
                                                        subject.complete
                                                            ? (subject.remark ??
                                                              undefined)
                                                            : 'Not every assessment is marked yet'
                                                    }
                                                >
                                                    {subject.mark === null
                                                        ? '—'
                                                        : `${score(subject.mark)} ${subject.grade}`}
                                                </TableCell>
                                            ))}
                                            {school.usesCredits ? (
                                                <TableCell className="text-right font-semibold tabular-nums">
                                                    {result.gpa?.toFixed(2) ??
                                                        '—'}
                                                </TableCell>
                                            ) : (
                                                <>
                                                    <TableCell className="text-right tabular-nums">
                                                        {score(result.total)}
                                                    </TableCell>
                                                    <TableCell className="text-right font-semibold tabular-nums">
                                                        {score(result.average)}
                                                    </TableCell>
                                                </>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <p className="border-t px-3 py-2 text-xs text-muted-foreground">
                            Grey marks are not complete: some assessments are
                            still unmarked.
                        </p>
                    </>
                )}
            </Panel>
        </>
    );
}
