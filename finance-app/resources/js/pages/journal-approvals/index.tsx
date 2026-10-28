import { Form, Head, Link } from '@inertiajs/react';
import { Check, ShieldCheck, Undo2, X } from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { Field } from '@/components/field';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import type { Tone } from '@miles/ui/components/status-badge';
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
import { useCan } from '@/lib/access';
import { useAmountStep } from '@/lib/format';
import {
    approve,
    reject,
    settings,
    withdraw,
} from '@/routes/journal-approvals';
import { show as journalRoute } from '@/routes/journals';
import { index as recurringJournals } from '@/routes/recurring-journals';

type Draft = {
    id: string;
    number: string;
    date: string;
    memo: string;
    totalMinor: number;
    status: string;
    submittedBy: string;
    submittedAt: string;
    own: boolean;
    reviewedBy: string | null;
    reviewedAt: string | null;
    reviewNote: string | null;
    journal: { id: string; number: string } | null;
    lines: {
        account: string;
        description: string | null;
        debitMinor: number;
        creditMinor: number;
        dimensions: string;
    }[];
};

type Props = {
    pending: Draft[];
    decided: Draft[];
    setting: { enabled: boolean; threshold: string };
    recurringAwaiting: number;
};

const STATUS: Record<string, { label: string; tone: Tone }> = {
    pending: { label: 'Waiting', tone: 'warning' },
    approved: { label: 'Approved', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'danger' },
    withdrawn: { label: 'Withdrawn', tone: 'neutral' },
};

/**
 * Manual journals waiting for a second person, recent decisions, and when
 * approval is needed.
 */
export default function JournalApprovals({
    pending,
    decided,
    setting,
    recurringAwaiting,
}: Props) {
    const can = useCan();
    const approver = can('finance.journals.approve');

    return (
        <>
            <Head title="Journal approvals" />
            <PageHeader
                title="Journal approvals"
                description="A second person checks manual journals before they reach the books. Nobody approves their own entry."
            />

            {approver && <ApprovalSetting setting={setting} />}

            {recurringAwaiting > 0 && (
                <p className="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    {recurringAwaiting === 1
                        ? '1 recurring journal is'
                        : `${recurringAwaiting} recurring journals are`}{' '}
                    waiting for approval and will not post until approved:{' '}
                    <Link
                        href={recurringJournals()}
                        className="font-medium underline"
                    >
                        Recurring journals
                    </Link>
                    .
                </p>
            )}

            <Panel className="mb-3">
                <PanelHeader
                    title="Waiting for approval"
                    description="Not in the books yet: they move no balance until approved."
                />
                {pending.length === 0 ? (
                    <EmptyState icon={ShieldCheck} title="Nothing waiting">
                        Journals that need approval appear here when they are
                        entered.
                    </EmptyState>
                ) : (
                    <div className="divide-y">
                        {pending.map((draft) => (
                            <PendingDraft
                                key={draft.id}
                                draft={draft}
                                approver={approver}
                                poster={can('finance.journals.post')}
                            />
                        ))}
                    </div>
                )}
            </Panel>

            <Panel>
                <PanelHeader title="Recent decisions" />
                {decided.length === 0 ? (
                    <PanelBody className="text-sm text-muted-foreground">
                        None yet.
                    </PanelBody>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Entry</TableHead>
                                <TableHead>Entered by</TableHead>
                                <TableHead>Decision</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {decided.map((draft) => (
                                <TableRow key={draft.id}>
                                    <TableCell>
                                        <span className="font-mono text-xs">
                                            {draft.number}
                                        </span>{' '}
                                        {draft.journal && (
                                            <Link
                                                href={journalRoute(
                                                    draft.journal.id,
                                                )}
                                                className="font-mono text-xs hover:underline"
                                            >
                                                → {draft.journal.number}
                                            </Link>
                                        )}
                                        <div className="text-xs text-muted-foreground">
                                            {draft.date} · {draft.memo}
                                        </div>
                                    </TableCell>
                                    <TableCell>{draft.submittedBy}</TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={STATUS[draft.status]?.tone}
                                        >
                                            {STATUS[draft.status]?.label ??
                                                draft.status}
                                        </StatusBadge>
                                        <div className="text-xs text-muted-foreground">
                                            {draft.reviewedBy}
                                            {draft.reviewedAt &&
                                                `, ${new Date(draft.reviewedAt).toLocaleString()}`}
                                            {draft.reviewNote &&
                                                `: ${draft.reviewNote}`}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={draft.totalMinor} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}

function PendingDraft({
    draft,
    approver,
    poster,
}: {
    draft: Draft;
    approver: boolean;
    poster: boolean;
}) {
    const [rejecting, setRejecting] = useState(false);

    return (
        <div className="grid gap-2 p-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <span className="font-mono text-xs">{draft.number}</span>{' '}
                    <span className="font-medium">{draft.memo}</span>
                    <div className="text-xs text-muted-foreground">
                        Dated {draft.date} · entered by {draft.submittedBy},{' '}
                        {new Date(draft.submittedAt).toLocaleString()}
                    </div>
                </div>
                <div className="flex flex-wrap gap-1">
                    {approver && !draft.own && (
                        <>
                            <Form {...approve.form(draft.id)}>
                                {({ processing, errors }) => (
                                    <>
                                        <Button
                                            size="sm"
                                            type="submit"
                                            disabled={processing}
                                        >
                                            <Check
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Approve and post
                                        </Button>
                                        {errors.draft && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {errors.draft}
                                            </p>
                                        )}
                                        {errors.entry_date && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {errors.entry_date}
                                            </p>
                                        )}
                                    </>
                                )}
                            </Form>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setRejecting((value) => !value)}
                            >
                                <X className="size-4" aria-hidden />
                                Reject
                            </Button>
                        </>
                    )}
                    {draft.own && poster && (
                        <Form {...withdraw.form(draft.id)}>
                            {({ processing }) => (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    type="submit"
                                    disabled={processing}
                                >
                                    <Undo2 className="size-4" aria-hidden />
                                    Withdraw
                                </Button>
                            )}
                        </Form>
                    )}
                    {draft.own && (
                        <span className="self-center text-xs text-muted-foreground">
                            Your entry: someone else approves it.
                        </span>
                    )}
                </div>
            </div>

            {rejecting && (
                <Form
                    {...reject.form(draft.id)}
                    className="flex flex-wrap items-end gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field
                                id={`reason-${draft.id}`}
                                label="Why"
                                error={errors.reason}
                                className="min-w-64 flex-1"
                            >
                                <Input
                                    id={`reason-${draft.id}`}
                                    name="reason"
                                    required
                                    maxLength={200}
                                />
                            </Field>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                Reject {draft.number}
                            </Button>
                        </>
                    )}
                </Form>
            )}

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Account</TableHead>
                        <TableHead>Description</TableHead>
                        <TableHead className="text-right">Debit</TableHead>
                        <TableHead className="text-right">Credit</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {draft.lines.map((line, index) => (
                        <TableRow key={index}>
                            <TableCell>
                                {line.account}
                                {line.dimensions && (
                                    <div className="text-xs text-muted-foreground">
                                        {line.dimensions}
                                    </div>
                                )}
                            </TableCell>
                            <TableCell className="text-muted-foreground">
                                {line.description}
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount minor={line.debitMinor} blankZero />
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount minor={line.creditMinor} blankZero />
                            </TableCell>
                        </TableRow>
                    ))}
                    <TableRow>
                        <TableCell colSpan={2} className="font-medium">
                            Total
                        </TableCell>
                        <TableCell className="text-right font-medium">
                            <Amount minor={draft.totalMinor} />
                        </TableCell>
                        <TableCell className="text-right font-medium">
                            <Amount minor={draft.totalMinor} />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    );
}

function ApprovalSetting({
    setting,
}: {
    setting: { enabled: boolean; threshold: string };
}) {
    const step = useAmountStep();
    const [enabled, setEnabled] = useState(setting.enabled);

    return (
        <Panel className="mb-3">
            <PanelHeader
                title="When approval is needed"
                description="For manual and recurring journals. Journals from invoices, bills, payments, banking and products post as before."
            />
            <PanelBody>
                <Form
                    {...settings.form()}
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <label className="flex h-9 items-center gap-2 text-sm">
                                <input
                                    type="hidden"
                                    name="journal_approval"
                                    value="0"
                                />
                                <input
                                    type="checkbox"
                                    name="journal_approval"
                                    value="1"
                                    checked={enabled}
                                    onChange={(event) =>
                                        setEnabled(event.target.checked)
                                    }
                                />
                                Manual journals need a second person's approval
                            </label>
                            {enabled && (
                                <Field
                                    id="threshold"
                                    label="From this amount up"
                                    error={errors.threshold}
                                    hint="Blank: every manual journal"
                                >
                                    <Input
                                        id="threshold"
                                        name="threshold"
                                        type="number"
                                        min="0"
                                        step={step}
                                        defaultValue={setting.threshold}
                                        className="w-40"
                                    />
                                </Field>
                            )}
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                Save
                            </Button>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}
