import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { COLLECTED } from '@/mel/lib/collect';
import type { CollectedStatus } from '@/mel/lib/collect';
import {
    CALCULATIONS,
    RUN_STATUS,
    describeCondition,
    figureText,
} from '@/mel/lib/indicator-mappings';
import type {
    Calculation,
    Condition,
    Figure,
    Question,
    RunStatus,
} from '@/mel/lib/indicator-mappings';
import { SUBMISSION, formatDateTime } from '@/mel/lib/mel';
import type { SubmissionStatus } from '@/mel/lib/mel';
import { indicators as indicatorsPage } from '@/routes/mel/collect/forms';
import { show as collectedPage } from '@/routes/mel/collect/submissions';
import { show as resultPage } from '@/routes/mel/submissions';

type Props = {
    run: Figure & {
        id: string;
        indicator: string;
        form: { id: string; name: string };
        period: { id: string; label: string };
        unit: { id: string; label: string };
        calculation: Calculation;
        question: string | null;
        distinctBy: string | null;
        condition: Condition | null;
        numeratorCondition: Condition | null;
        considered: number;
        accepted: number;
        used: number;
        excluded: { reason: string; label: string; count: number }[];
        status: RunStatus;
        result: {
            id: string;
            status: SubmissionStatus;
            fromThisRun: boolean;
        } | null;
        flag: string | null;
        newer: number;
        createdAt: string;
        author: string | null;
    };
    rows: {
        submissionId: string;
        label: string;
        author: string;
        completedAt: string;
        version: number;
        revision: number | null;
        statusNow: CollectedStatus;
        amount: string | null;
        inNumerator: boolean | null;
        standing: boolean;
    }[];
    amountsHidden: boolean;
    questions: Question[];
};

export default function CalculationShow({
    run,
    rows,
    amountsHidden,
    questions,
}: Props) {
    const changed = rows.filter((row) => row.statusNow !== 'accepted').length;

    return (
        <>
            <Head title={`${run.indicator}: calculation`} />
            <PageHeader
                title={run.indicator}
                description={`${run.period.label} · ${run.unit.label} · calculated from "${run.form.name}" on ${formatDateTime(run.createdAt)}${run.author ? ` by ${run.author}` : ''}.`}
                actions={
                    <Button variant="outline" asChild>
                        <Link href={indicatorsPage(run.form.id)}>
                            The form's indicators
                        </Link>
                    </Button>
                }
            />

            {(run.flag || changed > 0) && (
                <p className="mb-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                    {run.flag ??
                        `${changed} of the submissions counted here are no longer accepted.`}{' '}
                    The result sent to M&E is not changed by this: recalculate
                    from the form's indicators to update it.
                </p>
            )}

            <div className="grid gap-4 md:grid-cols-3">
                <Panel>
                    <PanelHeader title="Value" />
                    <PanelBody className="grid gap-2 text-sm">
                        <p className="text-3xl font-semibold tabular-nums">
                            {figureText(run, run.calculation)}
                        </p>
                        <p className="text-muted-foreground">
                            {CALCULATIONS[run.calculation].label}, from{' '}
                            {run.used} submission{run.used === 1 ? '' : 's'}.
                        </p>
                        <p className="flex items-center gap-2">
                            <StatusBadge tone={RUN_STATUS[run.status].tone}>
                                {RUN_STATUS[run.status].label}
                            </StatusBadge>
                            {run.status === 'superseded' && (
                                <span className="text-muted-foreground">
                                    A later calculation replaced it.
                                </span>
                            )}
                        </p>
                    </PanelBody>
                </Panel>
                <Panel>
                    <PanelHeader title="Result in M&E" />
                    <PanelBody className="grid gap-2 text-sm">
                        {run.result === null ? (
                            <p className="text-muted-foreground">None.</p>
                        ) : (
                            <>
                                <Link
                                    href={resultPage(run.result.id)}
                                    className="flex items-center gap-2"
                                >
                                    <StatusBadge
                                        tone={
                                            SUBMISSION[run.result.status].tone
                                        }
                                    >
                                        {SUBMISSION[run.result.status].label}
                                    </StatusBadge>
                                    <span className="underline-offset-4 hover:underline">
                                        Open the result
                                    </span>
                                </Link>
                                {!run.result.fromThisRun && (
                                    <p className="text-muted-foreground">
                                        The result now comes from a later
                                        calculation.
                                    </p>
                                )}
                            </>
                        )}
                        <p className="text-muted-foreground">
                            Accepted survey data never becomes a verified result
                            by itself: M&E verifies it first.
                        </p>
                    </PanelBody>
                </Panel>
                <Panel>
                    <PanelHeader title="What was counted" />
                    <PanelBody className="grid gap-1 text-sm">
                        <p>
                            {run.considered} received for this period and unit,{' '}
                            {run.accepted} accepted, {run.used} used.
                        </p>
                        {run.condition && (
                            <p className="text-muted-foreground">
                                Counted where{' '}
                                {describeCondition(run.condition, questions)}.
                            </p>
                        )}
                        {run.numeratorCondition && (
                            <p className="text-muted-foreground">
                                In the percentage where{' '}
                                {describeCondition(
                                    run.numeratorCondition,
                                    questions,
                                )}
                                .
                            </p>
                        )}
                        {run.distinctBy && (
                            <p className="text-muted-foreground">
                                Repeat visits counted once, by "
                                {questions.find(
                                    (item) => item.name === run.distinctBy,
                                )?.label ?? run.distinctBy}
                                ".
                            </p>
                        )}
                        {run.excluded.map((item) => (
                            <p
                                key={item.reason}
                                className="text-muted-foreground"
                            >
                                Left out: {item.count} · {item.label}
                            </p>
                        ))}
                    </PanelBody>
                </Panel>
            </div>

            <Panel className="mt-4">
                <PanelHeader
                    title="Submissions counted"
                    description={
                        amountsHidden
                            ? 'The amounts come from a personal question, so they are hidden: you do not have access to personal data.'
                            : 'Each one with the answers it was counted from (the original, or a correction).'
                    }
                />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Submission</TableHead>
                            <TableHead>Collected by</TableHead>
                            <TableHead>Completed</TableHead>
                            <TableHead>Answers</TableHead>
                            <TableHead className="text-right">
                                {run.calculation === 'percentage'
                                    ? 'In the percentage'
                                    : 'Adds'}
                            </TableHead>
                            <TableHead>Status now</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.submissionId}>
                                <TableCell>
                                    <Link
                                        href={collectedPage(row.submissionId)}
                                        className="font-mono underline-offset-4 hover:underline"
                                    >
                                        {row.label}
                                    </Link>
                                </TableCell>
                                <TableCell>{row.author}</TableCell>
                                <TableCell>
                                    {formatDateTime(row.completedAt)}
                                </TableCell>
                                <TableCell>
                                    Version {row.version}
                                    {row.revision === null
                                        ? ', original'
                                        : `, correction ${row.revision}`}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {run.calculation === 'percentage'
                                        ? row.inNumerator
                                            ? 'Yes'
                                            : 'No'
                                        : (row.amount ?? 'Hidden')}
                                </TableCell>
                                <TableCell>
                                    <StatusBadge
                                        tone={COLLECTED[row.statusNow].tone}
                                    >
                                        {COLLECTED[row.statusNow].label}
                                    </StatusBadge>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
