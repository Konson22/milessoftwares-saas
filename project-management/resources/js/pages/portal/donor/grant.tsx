import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
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
import { Field } from '@/components/field';
import { formatMoney } from '@/lib/money';
import {
    formatDate,
    formatDateTime,
    statusLabel,
    statusTone,
} from '@/lib/status';

type Report = {
    id: string;
    title: string;
    kind: string;
    dueOn: string;
    status: string;
    statusLabel: string;
    submittedOn: string | null;
    acceptedOn: string | null;
    canDecide: boolean;
    documents: { id: string; name: string; size: number }[];
};

type Project = {
    id: string;
    code: string;
    name: string;
    currency: string;
    allocatedMinor: number;
    progress: { status: string; percent: number } | null;
    milestones: { name: string; dueOn: string; reached: boolean }[] | null;
    tasks:
        | {
              number: string;
              title: string;
              status: string;
              dueOn: string | null;
          }[]
        | null;
    budget: { categories: { category: string; amountMinor: number }[] } | null;
    commitments: {
        committedMinor: number;
        categories: { category: string; amountMinor: number }[];
    } | null;
    spending: {
        paidMinor: number;
        lines: {
            paidOn: string | null;
            category: string;
            description: string;
            amountMinor: number;
        }[];
    } | null;
};

type Props = {
    grant: {
        id: string;
        reference: string;
        title: string;
        status: string;
        currency: string;
        amountMinor: number;
        receivedMinor: number;
        restricted: boolean;
        startOn: string | null;
        endOn: string | null;
    };
    shares: {
        budget: boolean;
        spending: boolean;
        commitments: boolean;
        progress: boolean;
        milestones: boolean;
        tasks: boolean;
    };
    amendments: {
        number: number;
        effectiveOn: string;
        amountAfterMinor: number;
        endAfter: string | null;
    }[];
    payments: {
        id: string;
        dueOn: string;
        amountMinor: number;
        receivedOn: string | null;
    }[];
    reports: Report[];
    projects: Project[];
    messages: {
        id: string;
        author: string;
        fromFunder: boolean;
        body: string;
        at: string;
    }[];
    /** This grant's address: the funder's own page, or the donor's view of a beneficiary. */
    base: string;
    /** Where the list of grants is. */
    back: string;
};

const keep = { preserveScroll: true };

function RevisionForm({
    base,
    report,
    onDone,
}: {
    base: string;
    report: Report;
    onDone: () => void;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <Form
            action={`${base}/reports/${report.id}/decision`}
            method="post"
            options={keep}
            onSuccess={onDone}
            className="mt-2 space-y-2"
        >
            <input type="hidden" name="status" value="revision_requested" />
            <Field
                label="What needs to change"
                id={`reason-${report.id}`}
                error={errors.reason}
            >
                <textarea
                    id={`reason-${report.id}`}
                    name="reason"
                    rows={3}
                    required
                    maxLength={2000}
                    className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                />
            </Field>
            <span className="flex gap-2">
                <Button type="submit" size="sm">
                    Ask for a revision
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onDone}
                >
                    Cancel
                </Button>
            </span>
        </Form>
    );
}

function ReportRow({ base, report }: { base: string; report: Report }) {
    const [revising, setRevising] = useState(false);

    return (
        <li className="border-b py-3 last:border-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="text-sm font-medium">{report.title}</p>
                    <p className="text-xs text-muted-foreground">
                        {report.kind}, due {formatDate(report.dueOn)}
                        {report.submittedOn &&
                            `, submitted ${formatDate(report.submittedOn)}`}
                        {report.acceptedOn &&
                            `, accepted ${formatDate(report.acceptedOn)}`}
                    </p>
                </div>
                <StatusBadge tone={statusTone(report.status)}>
                    {report.statusLabel}
                </StatusBadge>
            </div>
            {report.documents.length > 0 && (
                <ul className="mt-1 text-sm">
                    {report.documents.map((document) => (
                        <li key={document.id}>
                            <a
                                className="underline"
                                href={`${base}/reports/${report.id}/documents/${document.id}`}
                            >
                                {document.name}
                            </a>
                        </li>
                    ))}
                </ul>
            )}
            {report.canDecide && !revising && (
                <span className="mt-2 flex gap-2">
                    <Form
                        action={`${base}/reports/${report.id}/decision`}
                        method="post"
                        options={keep}
                    >
                        <input type="hidden" name="status" value="accepted" />
                        <Button type="submit" size="sm">
                            Accept
                        </Button>
                    </Form>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setRevising(true)}
                    >
                        Ask for a revision
                    </Button>
                </span>
            )}
            {report.canDecide && revising && (
                <RevisionForm
                    base={base}
                    report={report}
                    onDone={() => setRevising(false)}
                />
            )}
        </li>
    );
}

function ProjectPanel({ project }: { project: Project }) {
    const money = (minor: number) => formatMoney(minor, project.currency);

    return (
        <Panel className="mt-4">
            <PanelHeader
                title={`${project.code} ${project.name}`}
                description={`This grant gives ${money(project.allocatedMinor)}.`}
            />
            <PanelBody className="space-y-5">
                {project.progress && (
                    <section>
                        <h3 className="text-sm font-medium">
                            Progress: {project.progress.percent}%
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            {statusLabel(project.progress.status)}
                        </p>
                    </section>
                )}
                {project.milestones && (
                    <section>
                        <h3 className="text-sm font-medium">Milestones</h3>
                        {project.milestones.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No milestones yet.
                            </p>
                        ) : (
                            <ul className="mt-2 space-y-1 text-sm">
                                {project.milestones.map((milestone) => (
                                    <li
                                        key={`${milestone.name}-${milestone.dueOn}`}
                                        className="flex justify-between gap-2"
                                    >
                                        <span>{milestone.name}</span>
                                        <span className="text-muted-foreground">
                                            {milestone.reached
                                                ? 'Reached'
                                                : `Due ${formatDate(milestone.dueOn)}`}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
                {project.tasks && (
                    <section>
                        <h3 className="text-sm font-medium">Tasks</h3>
                        {project.tasks.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No tasks yet.
                            </p>
                        ) : (
                            <ul className="mt-2 space-y-1 text-sm">
                                {project.tasks.map((task) => (
                                    <li
                                        key={task.number}
                                        className="flex justify-between gap-2"
                                    >
                                        <span>
                                            <span className="text-muted-foreground">
                                                {task.number}
                                            </span>{' '}
                                            {task.title}
                                        </span>
                                        <span className="shrink-0 text-muted-foreground">
                                            {statusLabel(task.status)}
                                            {task.status !== 'done' &&
                                                task.dueOn &&
                                                `, due ${formatDate(task.dueOn)}`}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
                {project.budget && (
                    <section>
                        <h3 className="text-sm font-medium">
                            Approved budget of the whole project
                        </h3>
                        <ul className="mt-2 space-y-1 text-sm">
                            {project.budget.categories.map((line) => (
                                <li
                                    key={line.category}
                                    className="flex justify-between gap-2"
                                >
                                    <span>{line.category}</span>
                                    <span className="tabular-nums">
                                        {money(line.amountMinor)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {project.spending && (
                    <section>
                        <h3 className="text-sm font-medium">
                            Spending on this grant
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            {money(project.spending.paidMinor)} paid.
                        </p>
                        {project.spending.lines.length > 0 && (
                            <Table className="mt-2">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {project.spending.lines.map(
                                        (line, position) => (
                                            <TableRow key={position}>
                                                <TableCell>
                                                    {line.paidOn
                                                        ? formatDate(
                                                              line.paidOn,
                                                          )
                                                        : 'Not dated'}
                                                </TableCell>
                                                <TableCell>
                                                    {line.category}
                                                </TableCell>
                                                <TableCell>
                                                    {line.description}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {money(line.amountMinor)}
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </Table>
                        )}
                    </section>
                )}
                {project.commitments && (
                    <section>
                        <h3 className="text-sm font-medium">
                            Commitments on this grant
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            {money(project.commitments.committedMinor)} approved
                            and waiting to be paid.
                        </p>
                        {project.commitments.categories.length > 0 && (
                            <ul className="mt-2 space-y-1 text-sm">
                                {project.commitments.categories.map((line) => (
                                    <li
                                        key={line.category}
                                        className="flex justify-between gap-2"
                                    >
                                        <span>{line.category}</span>
                                        <span className="tabular-nums">
                                            {money(line.amountMinor)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
                {!project.progress &&
                    !project.milestones &&
                    !project.tasks &&
                    !project.budget &&
                    !project.spending &&
                    !project.commitments && (
                        <p className="text-sm text-muted-foreground">
                            No further detail has been shared on this project.
                        </p>
                    )}
            </PanelBody>
        </Panel>
    );
}

/** One shared grant, as the funder sees it. */
export default function DonorGrant({
    grant,
    amendments,
    payments,
    reports,
    projects,
    messages,
    base,
    back,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const money = (minor: number) => formatMoney(minor, grant.currency);

    return (
        <>
            <Head title={grant.reference} />
            <PageHeader
                title={`${grant.reference} ${grant.title}`}
                description={
                    grant.startOn
                        ? `${formatDate(grant.startOn)}${grant.endOn ? ` to ${formatDate(grant.endOn)}` : ''}`
                        : undefined
                }
                actions={
                    <span className="flex items-center gap-3">
                        <StatusBadge tone={statusTone(grant.status)}>
                            {statusLabel(grant.status)}
                        </StatusBadge>
                        <Link
                            href={back}
                            className="text-sm underline-offset-2 hover:underline"
                        >
                            All grants
                        </Link>
                    </span>
                }
            />

            <div className="grid gap-4 sm:grid-cols-2">
                <StatCard
                    label="Grant amount"
                    value={money(grant.amountMinor)}
                />
                <StatCard
                    label="Paid so far"
                    value={money(grant.receivedMinor)}
                />
            </div>

            {(errors.status || errors.body) && (
                <p role="alert" className="mt-4 text-sm text-destructive">
                    {errors.status ?? errors.body}
                </p>
            )}

            <Panel className="mt-6">
                <PanelHeader title="Reports" />
                <PanelBody>
                    {reports.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No reports scheduled.
                        </p>
                    ) : (
                        <ul>
                            {reports.map((report) => (
                                <ReportRow
                                    key={report.id}
                                    base={base}
                                    report={report}
                                />
                            ))}
                        </ul>
                    )}
                </PanelBody>
            </Panel>

            <Panel className="mt-6">
                <PanelHeader title="Payments" />
                <PanelBody>
                    {payments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No payments scheduled.
                        </p>
                    ) : (
                        <ul className="space-y-1 text-sm">
                            {payments.map((payment) => (
                                <li
                                    key={payment.id}
                                    className="flex justify-between gap-2"
                                >
                                    <span>
                                        Due {formatDate(payment.dueOn)}
                                        <span className="text-muted-foreground">
                                            {payment.receivedOn
                                                ? `, received ${formatDate(payment.receivedOn)}`
                                                : ', not received yet'}
                                        </span>
                                    </span>
                                    <span className="tabular-nums">
                                        {money(payment.amountMinor)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </PanelBody>
            </Panel>

            {amendments.length > 0 && (
                <Panel className="mt-6">
                    <PanelHeader title="Amendments" />
                    <PanelBody>
                        <ul className="space-y-1 text-sm">
                            {amendments.map((amendment) => (
                                <li
                                    key={amendment.number}
                                    className="flex justify-between gap-2"
                                >
                                    <span>
                                        Amendment {amendment.number}, from{' '}
                                        {formatDate(amendment.effectiveOn)}
                                        {amendment.endAfter &&
                                            `, ending ${formatDate(amendment.endAfter)}`}
                                    </span>
                                    <span className="tabular-nums">
                                        {money(amendment.amountAfterMinor)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </PanelBody>
                </Panel>
            )}

            {projects.map((project) => (
                <ProjectPanel key={project.id} project={project} />
            ))}

            <Panel className="mt-6">
                <PanelHeader title="Conversation" />
                <PanelBody className="space-y-3">
                    {messages.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No messages yet.
                        </p>
                    )}
                    <ul className="space-y-2 text-sm">
                        {messages.map((message) => (
                            <li key={message.id}>
                                <p className="text-xs text-muted-foreground">
                                    {message.author},{' '}
                                    {formatDateTime(message.at)}
                                </p>
                                <p className="whitespace-pre-wrap">
                                    {message.body}
                                </p>
                            </li>
                        ))}
                    </ul>
                    <Form
                        action={`${base}/messages`}
                        method="post"
                        options={keep}
                        resetOnSuccess
                        className="space-y-2"
                    >
                        <Field
                            label="Write to the organization"
                            id="donor-message"
                            error={errors.body}
                        >
                            <textarea
                                id="donor-message"
                                name="body"
                                rows={3}
                                required
                                maxLength={5000}
                                className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                            />
                        </Field>
                        <Button type="submit" size="sm" variant="outline">
                            Send
                        </Button>
                    </Form>
                </PanelBody>
            </Panel>
        </>
    );
}
