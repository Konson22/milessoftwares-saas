import { Form, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, Select } from '@/components/field';
import type { WorkFrame } from '@/components/partner-work';
import { period, WorkPage } from '@/components/partner-work';
import { Status } from '@/components/status';
import { formatDate } from '@/lib/status';
import type { Option } from '@/types';

type ReportRow = {
    id: string;
    reference: string;
    title: string;
    category: string;
    periodStart: string | null;
    periodEnd: string | null;
    dueOn: string | null;
    status: string;
    statusLabel: string;
    overdue: boolean;
    submitter: string;
    reviewer: string;
    direction: 'outgoing' | 'incoming' | 'other';
    url: string | null;
};

type ObligationRow = {
    id: string;
    title: string;
    category: string;
    frequency: string;
    startsOn: string;
    endsOn: string;
    dueDays: number;
    responsible: string;
    reviewer: string;
    status: string;
    reports: number;
    endUrl: string;
};

type Party = Option & { submits: boolean; reviews: boolean };

type Props = {
    frame: WorkFrame;
    reports: ReportRow[];
    obligations: ObligationRow[];
    can: { create: boolean; manageObligations: boolean };
    categories: Option[];
    frequencies: Option[];
    reviewers: Option[];
    defaultReviewer: string;
    parties: Party[];
    urls: { store: string; obligations: string | null };
};

export default function Reports({
    frame,
    reports,
    obligations,
    can,
    categories,
    frequencies,
    reviewers,
    defaultReviewer,
    parties,
    urls,
}: Props) {
    const overdue = reports.filter((report) => report.overdue).length;

    return (
        <WorkPage frame={frame} title="Reports">
            <Panel>
                <PanelHeader
                    title="Reports"
                    description={
                        overdue > 0
                            ? `${overdue} overdue`
                            : 'Reports between the parties of this project'
                    }
                />
                {reports.length === 0 ? (
                    <EmptyState icon={FileText} title="No reports yet">
                        Reports appear here when you write one, when one is sent
                        to you, or when a report is published.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Report</TableHead>
                                <TableHead>Period</TableHead>
                                <TableHead>From → to</TableHead>
                                <TableHead>Due</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {reports.map((report) => (
                                <TableRow key={report.id}>
                                    <TableCell>
                                        {report.url ? (
                                            <Link
                                                href={report.url}
                                                className="font-medium hover:underline"
                                            >
                                                {report.reference} ·{' '}
                                                {report.title}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">
                                                {report.reference} ·{' '}
                                                {report.title}
                                            </span>
                                        )}
                                        <div className="text-xs text-muted-foreground">
                                            {report.category}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {period(
                                            report.periodStart,
                                            report.periodEnd,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {report.submitter} → {report.reviewer}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {report.dueOn
                                            ? formatDate(report.dueOn)
                                            : '—'}
                                        {report.overdue && (
                                            <StatusBadge
                                                tone="danger"
                                                className="ml-2"
                                            >
                                                Overdue
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Status status={report.status} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            {can.create && (
                <Panel>
                    <PanelHeader
                        title="Write a report"
                        description="It starts as a draft only you can see."
                    />
                    <PanelBody>
                        <Form
                            action={urls.store}
                            method="post"
                            className="grid gap-3 sm:grid-cols-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="title"
                                        label="Title"
                                        error={errors.title}
                                    >
                                        <Input
                                            id="title"
                                            name="title"
                                            required
                                            maxLength={200}
                                        />
                                    </Field>
                                    <Field
                                        id="category"
                                        label="Kind"
                                        error={errors.category}
                                    >
                                        <Select
                                            id="category"
                                            name="category"
                                            options={categories}
                                            required
                                        />
                                    </Field>
                                    <Field
                                        id="period_start"
                                        label="Period from"
                                        error={errors.period_start}
                                    >
                                        <Input
                                            id="period_start"
                                            name="period_start"
                                            type="date"
                                        />
                                    </Field>
                                    <Field
                                        id="period_end"
                                        label="Period to"
                                        error={errors.period_end}
                                    >
                                        <Input
                                            id="period_end"
                                            name="period_end"
                                            type="date"
                                        />
                                    </Field>
                                    <Field
                                        id="due_on"
                                        label="Due"
                                        error={errors.due_on}
                                    >
                                        <Input
                                            id="due_on"
                                            name="due_on"
                                            type="date"
                                        />
                                    </Field>
                                    <Field
                                        id="reviewer"
                                        label="Send to"
                                        error={errors.reviewer}
                                        hint="The party above you that reviews reports, unless you choose another."
                                    >
                                        <Select
                                            id="reviewer"
                                            name="reviewer"
                                            options={reviewers}
                                            defaultValue={defaultReviewer}
                                        />
                                    </Field>
                                    <span className="sm:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Create draft
                                        </Button>
                                    </span>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {frame.side === 'owner' &&
                (obligations.length > 0 || can.manageObligations) && (
                    <Panel>
                        <PanelHeader
                            title="Reporting obligations"
                            description="Who reports what, how often, and to whom. Each period becomes a report."
                        />
                        {obligations.length > 0 && (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Obligation</TableHead>
                                        <TableHead>From → to</TableHead>
                                        <TableHead>Span</TableHead>
                                        <TableHead>Reports</TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {obligations.map((obligation) => (
                                        <TableRow key={obligation.id}>
                                            <TableCell>
                                                <span className="font-medium">
                                                    {obligation.title}
                                                </span>
                                                <div className="text-xs text-muted-foreground">
                                                    {obligation.category} ·{' '}
                                                    {obligation.frequency} · due{' '}
                                                    {obligation.dueDays} days
                                                    after each period
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {obligation.responsible} →{' '}
                                                {obligation.reviewer}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {period(
                                                    obligation.startsOn,
                                                    obligation.endsOn,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {obligation.reports}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {obligation.status ===
                                                    'active' &&
                                                can.manageObligations ? (
                                                    <Form
                                                        action={
                                                            obligation.endUrl
                                                        }
                                                        method="post"
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                        >
                                                            End
                                                        </Button>
                                                    </Form>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        {obligation.status}
                                                    </span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        {can.manageObligations && urls.obligations && (
                            <PanelBody>
                                <Form
                                    action={urls.obligations}
                                    method="post"
                                    resetOnSuccess
                                    options={{ preserveScroll: true }}
                                    className="grid gap-3 sm:grid-cols-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                id="o-title"
                                                label="Title"
                                                error={errors.title}
                                            >
                                                <Input
                                                    id="o-title"
                                                    name="title"
                                                    required
                                                    maxLength={160}
                                                />
                                            </Field>
                                            <Field
                                                id="o-category"
                                                label="Kind"
                                                error={errors.category}
                                            >
                                                <Select
                                                    id="o-category"
                                                    name="category"
                                                    options={categories}
                                                    required
                                                />
                                            </Field>
                                            <Field
                                                id="o-frequency"
                                                label="How often"
                                                error={errors.frequency}
                                            >
                                                <Select
                                                    id="o-frequency"
                                                    name="frequency"
                                                    options={frequencies}
                                                    required
                                                />
                                            </Field>
                                            <Field
                                                id="o-starts"
                                                label="First day"
                                                error={errors.starts_on}
                                            >
                                                <Input
                                                    id="o-starts"
                                                    name="starts_on"
                                                    type="date"
                                                    required
                                                />
                                            </Field>
                                            <Field
                                                id="o-ends"
                                                label="Last day"
                                                error={errors.ends_on}
                                            >
                                                <Input
                                                    id="o-ends"
                                                    name="ends_on"
                                                    type="date"
                                                    required
                                                />
                                            </Field>
                                            <Field
                                                id="o-due"
                                                label="Days allowed after each period"
                                                error={errors.due_days}
                                            >
                                                <Input
                                                    id="o-due"
                                                    name="due_days"
                                                    type="number"
                                                    min={0}
                                                    max={365}
                                                    defaultValue={15}
                                                    required
                                                />
                                            </Field>
                                            <Field
                                                id="o-responsible"
                                                label="Reported by"
                                                error={errors.responsible}
                                            >
                                                <Select
                                                    id="o-responsible"
                                                    name="responsible"
                                                    options={parties.filter(
                                                        (party) =>
                                                            party.submits,
                                                    )}
                                                />
                                            </Field>
                                            <Field
                                                id="o-reviewer"
                                                label="Reviewed by"
                                                error={errors.reviewer}
                                            >
                                                <Select
                                                    id="o-reviewer"
                                                    name="reviewer"
                                                    options={parties.filter(
                                                        (party) =>
                                                            party.reviews,
                                                    )}
                                                />
                                            </Field>
                                            <label className="flex items-center gap-2 self-end text-sm">
                                                <input
                                                    type="hidden"
                                                    name="publish_after_approval"
                                                    value="0"
                                                />
                                                <input
                                                    type="checkbox"
                                                    name="publish_after_approval"
                                                    value="1"
                                                />
                                                Publish once approved (a
                                                reminder to the reviewer)
                                            </label>
                                            <span className="sm:col-span-3">
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Set up reporting
                                                </Button>
                                            </span>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        )}
                    </Panel>
                )}
        </WorkPage>
    );
}
