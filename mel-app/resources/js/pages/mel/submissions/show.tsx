import { Form, Head, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import type { ReactNode } from 'react';
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
import { TextAreaField } from '@/mel/components/fields';
import {
    SUBMISSION,
    formatBytes,
    formatDateTime,
    formatFigure,
    formatTarget,
    isFraction,
} from '@/mel/lib/mel';
import type {
    IndicatorDefinition,
    SubmissionStatus,
    Target,
} from '@/mel/lib/mel';
import { entry } from '@/routes/mel/data';
import { download } from '@/routes/mel/evidence';
import { show as indicatorPage } from '@/routes/mel/indicators';
import {
    discard,
    publish,
    reject,
    review,
    show,
    submit,
    verify,
} from '@/routes/mel/submissions';

type Props = {
    submission: {
        id: string;
        status: SubmissionStatus;
        revision: number;
        source: string;
        programme: { id: string; code: string };
        indicator: { id: string; code: string };
        period: { id: string; label: string };
        unit: { id: string; code: string; name: string };
        value: string | null;
        numerator: string | null;
        denominator: string | null;
        computed: string | null;
        narrative: string | null;
        deviationNote: string | null;
        createdBy: string;
        submittedBy: string | null;
        submittedAt: string | null;
        verified: {
            by: string;
            at: string;
            publishedBy: string | null;
            publishedAt: string | null;
        } | null;
        supersedesId: string | null;
    };
    version: IndicatorDefinition;
    target: Target | null;
    cells: {
        breakdown: string | null;
        category: string;
        value: string | null;
        numerator: string | null;
        denominator: string | null;
    }[];
    evidence: {
        id: string;
        name: string;
        size: number;
        shareWithDonors: boolean;
    }[];
    decisions: {
        action: string;
        comment: string | null;
        user: string;
        at: string;
    }[];
    can: {
        edit: boolean;
        discard: boolean;
        review: boolean;
        verify: boolean;
        reject: boolean;
        publish: boolean;
        correct: boolean;
    };
    ownSubmission: boolean;
};

const ACTIONS: Record<string, string> = {
    submitted: 'Submitted',
    review_started: 'Review started',
    verified: 'Verified',
    rejected: 'Sent back',
    published: 'Published',
    superseded: 'Replaced by a correction',
};

export default function SubmissionShow({
    submission,
    version,
    target,
    cells,
    evidence,
    decisions,
    can,
    ownSubmission,
}: Props) {
    const status = SUBMISSION[submission.status];
    const entryHref = entry({
        programme: submission.programme.id,
        period: submission.period.id,
        indicator: submission.indicator.id,
        unit: submission.unit.id,
    });
    const reviewing = can.verify || can.reject;

    return (
        <>
            <Head
                title={`${submission.indicator.code} · ${submission.unit.code} · ${submission.period.label}`}
            />
            <PageHeader
                title={
                    <>
                        <Link
                            href={indicatorPage({
                                programme: submission.programme.id,
                                indicator: submission.indicator.id,
                            })}
                            className="text-muted-foreground hover:underline"
                        >
                            {submission.indicator.code}
                        </Link>{' '}
                        {version.name}
                    </>
                }
                badges={
                    <>
                        <StatusBadge tone={status.tone}>
                            {status.label}
                        </StatusBadge>
                        {submission.revision > 1 && (
                            <StatusBadge>
                                Correction, revision {submission.revision}
                            </StatusBadge>
                        )}
                    </>
                }
            />

            <div className="grid max-w-4xl gap-3">
                <Panel>
                    <PanelBody className="grid gap-4 text-sm sm:grid-cols-4">
                        <Fact label="Programme">
                            {submission.programme.code}
                        </Fact>
                        <Fact label="Unit">
                            {submission.unit.code} · {submission.unit.name}
                        </Fact>
                        <Fact label="Period">{submission.period.label}</Fact>
                        <Fact label="Definition">v{version.version}</Fact>
                        <Fact label="Result">
                            <span className="text-lg font-semibold tabular-nums">
                                {formatFigure(submission.computed, version)}
                            </span>
                            {isFraction(version.measure) &&
                                submission.numerator !== null && (
                                    <span className="block text-xs text-muted-foreground">
                                        {formatFigure(submission.numerator, {
                                            ...version,
                                            measure: 'number',
                                            unitLabel: null,
                                        })}{' '}
                                        {version.numeratorLabel ?? ''} of{' '}
                                        {formatFigure(submission.denominator, {
                                            ...version,
                                            measure: 'number',
                                            unitLabel: null,
                                        })}{' '}
                                        {version.denominatorLabel ?? ''}
                                    </span>
                                )}
                        </Fact>
                        <Fact label="Target">
                            {formatTarget(target, version)}
                        </Fact>
                        <Fact label="Entered by">
                            {submission.createdBy}
                            {submission.source !== 'manual' && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {submission.source}
                                </span>
                            )}
                        </Fact>
                        <Fact label="Submitted">
                            {submission.submittedBy
                                ? `${submission.submittedBy}, ${formatDateTime(submission.submittedAt)}`
                                : '—'}
                        </Fact>
                        {submission.verified && (
                            <>
                                <Fact label="Verified">
                                    {submission.verified.by},{' '}
                                    {formatDateTime(submission.verified.at)}
                                </Fact>
                                <Fact label="Published">
                                    {submission.verified.publishedBy
                                        ? `${submission.verified.publishedBy}, ${formatDateTime(submission.verified.publishedAt)}`
                                        : 'Not yet'}
                                </Fact>
                            </>
                        )}
                        {submission.narrative && (
                            <Fact label="Narrative" wide>
                                {submission.narrative}
                            </Fact>
                        )}
                        {submission.deviationNote && (
                            <Fact label="Why it differs from the target" wide>
                                {submission.deviationNote}
                            </Fact>
                        )}
                        {submission.supersedesId && (
                            <Fact label="Corrects" wide>
                                <Link
                                    href={show(submission.supersedesId)}
                                    className="underline"
                                >
                                    The earlier result
                                </Link>
                            </Fact>
                        )}
                    </PanelBody>
                </Panel>

                {cells.length > 0 && (
                    <Panel>
                        <PanelHeader title="Disaggregation" />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>By</TableHead>
                                    <TableHead>Group</TableHead>
                                    <TableHead className="text-right">
                                        Value
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {cells.map((cell, index) => (
                                    <TableRow key={index}>
                                        <TableCell>{cell.breakdown}</TableCell>
                                        <TableCell>{cell.category}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatFigure(cell.value, version)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>
                )}

                <Panel>
                    <PanelHeader title="Evidence" />
                    {evidence.length === 0 ? (
                        <p className="p-3 text-sm text-muted-foreground">
                            None you can see.
                        </p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {evidence.map((file) => (
                                <li
                                    key={file.id}
                                    className="flex items-center gap-2 px-3 py-2"
                                >
                                    <FileText
                                        className="size-4 text-muted-foreground"
                                        aria-hidden
                                    />
                                    <a
                                        href={download.url(file.id)}
                                        className="hover:underline"
                                    >
                                        {file.name}
                                    </a>
                                    <span className="text-xs text-muted-foreground">
                                        {formatBytes(file.size)}
                                    </span>
                                    {file.shareWithDonors && (
                                        <StatusBadge tone="info">
                                            Donors may see it
                                        </StatusBadge>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                {(reviewing || can.review || can.publish) && (
                    <Panel>
                        <PanelHeader
                            title="Review"
                            description="Check the figure against its evidence. Verified results go into reports; published ones are shown to donors."
                        />
                        <PanelBody className="grid gap-3">
                            {can.review && (
                                <Form
                                    {...review.form(submission.id)}
                                    options={{ preserveScroll: true }}
                                >
                                    <Button type="submit" variant="outline">
                                        Start review
                                    </Button>
                                </Form>
                            )}
                            {can.verify && (
                                <Form
                                    {...verify.form(submission.id)}
                                    options={{ preserveScroll: true }}
                                    className="grid gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            {can.verify && (
                                                <>
                                                    <TextAreaField
                                                        name="comment"
                                                        label="Comment (optional)"
                                                        rows={2}
                                                        error={errors.comment}
                                                        prefix="verify-"
                                                    />
                                                    <div>
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Verify
                                                        </Button>
                                                    </div>
                                                </>
                                            )}
                                            {errors.submission && (
                                                <p className="text-sm text-destructive">
                                                    {errors.submission}
                                                </p>
                                            )}
                                        </>
                                    )}
                                </Form>
                            )}
                            {can.reject && (
                                <Form
                                    {...reject.form(submission.id)}
                                    options={{ preserveScroll: true }}
                                    className="grid gap-3 border-t pt-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <TextAreaField
                                                name="comment"
                                                label="Why it goes back"
                                                hint="The person who entered it sees this."
                                                rows={2}
                                                error={errors.comment}
                                                prefix="reject-"
                                            />
                                            <div>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    Send back for correction
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            )}
                            {ownSubmission && !can.verify && reviewing && (
                                <p className="text-sm text-muted-foreground">
                                    You submitted this result, so someone else
                                    verifies it.
                                </p>
                            )}
                            {can.publish && (
                                <Form
                                    {...publish.form(submission.id)}
                                    options={{ preserveScroll: true }}
                                    className="flex items-center gap-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Publish
                                            </Button>
                                            {errors.submission && (
                                                <p className="text-sm text-destructive">
                                                    {errors.submission}
                                                </p>
                                            )}
                                        </>
                                    )}
                                </Form>
                            )}
                        </PanelBody>
                    </Panel>
                )}

                {(can.edit || can.correct || can.discard) && (
                    <div className="flex flex-wrap gap-2">
                        {(can.edit || can.correct) && (
                            <Button variant="outline" asChild>
                                <Link href={entryHref}>
                                    {can.correct
                                        ? 'Correct this result'
                                        : 'Edit'}
                                </Link>
                            </Button>
                        )}
                        {can.edit && submission.status === 'rejected' && (
                            <Form {...submit.form(submission.id)}>
                                <Button type="submit">Submit again</Button>
                            </Form>
                        )}
                        {can.discard && (
                            <Form {...discard.form(submission.id)}>
                                <Button type="submit" variant="ghost">
                                    Discard draft
                                </Button>
                            </Form>
                        )}
                    </div>
                )}

                {decisions.length > 0 && (
                    <Panel>
                        <PanelHeader title="On record" />
                        <ul className="divide-y text-sm">
                            {decisions.map((decision, index) => (
                                <li key={index} className="px-3 py-2">
                                    <div className="flex flex-wrap justify-between gap-2">
                                        <span>
                                            <span className="font-medium">
                                                {ACTIONS[decision.action] ??
                                                    decision.action}
                                            </span>{' '}
                                            by {decision.user}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {formatDateTime(decision.at)}
                                        </span>
                                    </div>
                                    {decision.comment && (
                                        <p className="mt-1 whitespace-pre-line text-muted-foreground">
                                            {decision.comment}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}
            </div>
        </>
    );
}

function Fact({
    label,
    wide = false,
    children,
}: {
    label: string;
    wide?: boolean;
    children: ReactNode;
}) {
    return (
        <div className={wide ? 'sm:col-span-4' : ''}>
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="mt-0.5 whitespace-pre-line">{children}</div>
        </div>
    );
}
