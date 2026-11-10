import { Form, router, usePage } from '@inertiajs/react';
import { FileText, Receipt, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { PayForm } from '@/components/pay-form';
import { ProjectFrame } from '@/components/project-frame';
import { formatMoney } from '@/lib/money';
import { exportMethod as exportSpending } from '@/routes/projects/expenses';
import { formatDate, formatDateTime, statusTone } from '@/lib/status';
import {
    approve,
    cancel,
    pay,
    reject,
    request as requestSpending,
    store as recordExpense,
} from '@/routes/projects/expenses';
import {
    destroy as detachDocument,
    show as downloadDocument,
    store as attachDocument,
} from '@/routes/projects/expenses/documents';
import type { Option, ProjectHeader } from '@/types';

type Expense = {
    id: string;
    category: string;
    description: string;
    supplier: string | null;
    amountMinor: number;
    status: 'requested' | 'approved' | 'paid' | 'rejected' | 'cancelled';
    statusLabel: string;
    direct: boolean;
    grant: string | null;
    requestedBy: string;
    requestedAt: string;
    decidedBy: string | null;
    decisionNote: string | null;
    paidOn: string | null;
    paymentReference: string | null;
    documents: { id: string; name: string; size: number }[];
    can: {
        decide: boolean;
        pay: boolean;
        cancel: boolean;
        attach: boolean;
        detach: boolean;
    };
};

type Props = {
    project: ProjectHeader;
    expenses: Expense[];
    finance: boolean;
    canRequest: boolean;
    canRecord: boolean;
    categories: Option[];
    grants: Option[];
    today: string;
};

/**
 * Spending on the project, from request to payment, with the documents
 * behind it. Without finance access, a member sees their own requests.
 */
export default function Expenses({
    project,
    expenses,
    finance,
    canRequest,
    canRecord,
    categories,
    grants,
    today,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <ProjectFrame project={project} title="Spending">
            {finance && (
                <p className="mb-4 text-right">
                    <a
                        href={exportSpending.url({ project: project.id })}
                        className="text-sm underline"
                    >
                        Download CSV
                    </a>
                </p>
            )}
            {(errors.expense || errors.document) && (
                <p
                    role="alert"
                    className="mb-4 border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                >
                    {errors.expense ?? errors.document}
                </p>
            )}

            <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                <Panel>
                    <PanelHeader
                        title={finance ? 'Spending' : 'Your requests'}
                        description="Requested, then approved (committed), then paid. Nothing is deleted: cancelled and rejected spending stays on record."
                    />
                    {expenses.length === 0 ? (
                        <EmptyState icon={Receipt} title="No spending yet" />
                    ) : (
                        <ul className="divide-y">
                            {expenses.map((expense) => (
                                <ExpenseRow
                                    key={expense.id}
                                    project={project}
                                    expense={expense}
                                    today={today}
                                />
                            ))}
                        </ul>
                    )}
                </Panel>

                <div className="grid content-start gap-4">
                    {canRequest && (
                        <Panel>
                            <PanelHeader
                                title="Request spending"
                                description="Someone else approves it against the budget, then it is paid."
                            />
                            <PanelBody>
                                <SpendingForm
                                    form={requestSpending.form({
                                        project: project.id,
                                    })}
                                    prefix="request"
                                    categories={categories}
                                    grants={grants}
                                    submit="Request"
                                />
                            </PanelBody>
                        </Panel>
                    )}
                    {canRecord && (
                        <Panel>
                            <PanelHeader
                                title="Record a payment already made"
                                description="Skips approval and the budget check; marked as recorded directly and audited."
                            />
                            <PanelBody>
                                <SpendingForm
                                    form={recordExpense.form({
                                        project: project.id,
                                    })}
                                    prefix="record"
                                    categories={categories}
                                    grants={grants}
                                    submit="Record expense"
                                    extra={(errors) => (
                                        <Field
                                            id="record-date"
                                            label="Paid on"
                                            error={errors.spent_on}
                                        >
                                            <Input
                                                id="record-date"
                                                name="spent_on"
                                                type="date"
                                                required
                                                max={today}
                                                defaultValue={today}
                                            />
                                        </Field>
                                    )}
                                />
                            </PanelBody>
                        </Panel>
                    )}
                </div>
            </div>
        </ProjectFrame>
    );
}

function SpendingForm({
    form,
    prefix,
    categories,
    grants,
    submit,
    extra,
}: {
    form: ReturnType<typeof requestSpending.form>;
    prefix: string;
    categories: Option[];
    grants: Option[];
    submit: string;
    extra?: (errors: Record<string, string | undefined>) => ReactNode;
}) {
    return (
        <Form
            {...form}
            resetOnSuccess={['description', 'supplier', 'amount']}
            options={{ preserveScroll: true }}
            className="grid gap-3 sm:grid-cols-2"
        >
            {({ errors, processing }) => (
                <>
                    <Field
                        id={`${prefix}-description`}
                        label="What"
                        error={errors.description}
                        className="sm:col-span-2"
                    >
                        <Input
                            id={`${prefix}-description`}
                            name="description"
                            required
                            {...invalid(
                                `${prefix}-description`,
                                errors.description,
                            )}
                        />
                    </Field>
                    <Field
                        id={`${prefix}-category`}
                        label="Category"
                        error={errors.category}
                    >
                        <Select
                            id={`${prefix}-category`}
                            name="category"
                            options={categories}
                        />
                    </Field>
                    <Field
                        id={`${prefix}-amount`}
                        label="Amount"
                        error={errors.amount}
                    >
                        <Input
                            id={`${prefix}-amount`}
                            name="amount"
                            required
                            inputMode="decimal"
                            {...invalid(`${prefix}-amount`, errors.amount)}
                        />
                    </Field>
                    <Field
                        id={`${prefix}-supplier`}
                        label="Supplier"
                        error={errors.supplier}
                    >
                        <Input id={`${prefix}-supplier`} name="supplier" />
                    </Field>
                    {grants.length > 0 && (
                        <Field
                            id={`${prefix}-grant`}
                            label="Charged to grant"
                            error={errors.grant_id}
                        >
                            <Select
                                id={`${prefix}-grant`}
                                name="grant_id"
                                options={grants}
                                placeholder="No grant"
                            />
                        </Field>
                    )}
                    {extra?.(errors)}
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={processing}>
                            {submit}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function ExpenseRow({
    project,
    expense,
    today,
}: {
    project: ProjectHeader;
    expense: Expense;
    today: string;
}) {
    const ids = { project: project.id, expense: expense.id };
    const closed =
        expense.status === 'rejected' || expense.status === 'cancelled';

    return (
        <li className="grid gap-2 px-3 py-3 text-sm">
            <div className="flex flex-wrap items-start gap-2">
                <div className="min-w-0 flex-1">
                    <p
                        className={
                            closed ? 'line-through opacity-60' : 'font-medium'
                        }
                    >
                        {expense.description}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {[
                            expense.category,
                            expense.supplier,
                            expense.grant && `grant ${expense.grant}`,
                            `by ${expense.requestedBy}, ${formatDateTime(expense.requestedAt)}`,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </div>
                <span className="tabular-nums">
                    {formatMoney(expense.amountMinor, project.currency)}
                </span>
                <StatusBadge tone={statusTone(expense.status)}>
                    {expense.statusLabel}
                </StatusBadge>
            </div>

            {(expense.decidedBy ||
                expense.decisionNote ||
                expense.paidOn ||
                expense.direct) && (
                <p className="text-xs text-muted-foreground">
                    {[
                        expense.direct && 'Recorded directly',
                        expense.decidedBy && `Decided by ${expense.decidedBy}`,
                        expense.decisionNote && `“${expense.decisionNote}”`,
                        expense.paidOn && `Paid ${formatDate(expense.paidOn)}`,
                        expense.paymentReference &&
                            `ref. ${expense.paymentReference}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            )}

            {expense.documents.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {expense.documents.map((document) => (
                        <li
                            key={document.id}
                            className="flex items-center gap-1 border px-2 py-0.5 text-xs"
                        >
                            <FileText className="size-3.5" />
                            <a
                                href={
                                    downloadDocument({
                                        ...ids,
                                        document: document.id,
                                    }).url
                                }
                                className="hover:underline"
                            >
                                {document.name}
                            </a>
                            <span className="text-muted-foreground">
                                {Math.max(1, Math.round(document.size / 1024))}{' '}
                                KB
                            </span>
                            {expense.can.detach && (
                                <button
                                    type="button"
                                    aria-label={`Remove ${document.name}`}
                                    className="text-muted-foreground hover:text-destructive"
                                    onClick={() =>
                                        router.delete(
                                            detachDocument.url({
                                                ...ids,
                                                document: document.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex flex-wrap gap-2">
                {expense.can.decide && (
                    <Action label="Approve or reject">
                        <Form
                            {...approve.form(ids)}
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ processing }) => (
                                <>
                                    <Field
                                        id={`approve-note-${expense.id}`}
                                        label="Note (optional)"
                                        className="min-w-40 flex-1"
                                    >
                                        <Input
                                            id={`approve-note-${expense.id}`}
                                            name="note"
                                        />
                                    </Field>
                                    <Button type="submit" disabled={processing}>
                                        Approve
                                    </Button>
                                </>
                            )}
                        </Form>
                        <Form
                            {...reject.form(ids)}
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id={`reject-reason-${expense.id}`}
                                        label="Reason"
                                        error={errors.reason}
                                        className="min-w-40 flex-1"
                                    >
                                        <Input
                                            id={`reject-reason-${expense.id}`}
                                            name="reason"
                                            required
                                        />
                                    </Field>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Reject
                                    </Button>
                                </>
                            )}
                        </Form>
                    </Action>
                )}
                {expense.can.pay && (
                    <Action label="Record payment">
                        <PayForm
                            form={pay.form(ids)}
                            id={expense.id}
                            today={today}
                        />
                    </Action>
                )}
                {expense.can.attach && (
                    <Action label="Attach a document">
                        <Form
                            {...attachDocument.form(ids)}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id={`document-${expense.id}`}
                                        label="Receipt, invoice or quote (10 MB at most)"
                                        error={errors.document}
                                        className="min-w-40 flex-1"
                                    >
                                        <Input
                                            id={`document-${expense.id}`}
                                            name="document"
                                            type="file"
                                            required
                                        />
                                    </Field>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Upload
                                    </Button>
                                </>
                            )}
                        </Form>
                    </Action>
                )}
                {expense.can.cancel && (
                    <Action
                        label={
                            expense.status === 'paid'
                                ? 'Void payment'
                                : 'Cancel'
                        }
                    >
                        <Form
                            {...cancel.form(ids)}
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id={`cancel-reason-${expense.id}`}
                                        label={
                                            expense.status === 'paid'
                                                ? 'Why is it voided?'
                                                : expense.status === 'approved'
                                                  ? 'Why is this commitment released?'
                                                  : 'Reason (optional)'
                                        }
                                        error={errors.reason}
                                        className="min-w-40 flex-1"
                                    >
                                        <Input
                                            id={`cancel-reason-${expense.id}`}
                                            name="reason"
                                            required={
                                                expense.status === 'paid' ||
                                                expense.status === 'approved'
                                            }
                                        />
                                    </Field>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        {expense.status === 'paid'
                                            ? 'Void'
                                            : 'Cancel it'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </Action>
                )}
            </div>
        </li>
    );
}

/** A disclosed action under an expense. */
function Action({ label, children }: { label: string; children: ReactNode }) {
    return (
        <details className="group w-full border px-2 py-1 open:pb-2 sm:w-auto sm:min-w-64 sm:open:w-full">
            <summary className="cursor-pointer text-xs font-medium text-muted-foreground select-none group-open:mb-2">
                {label}
            </summary>
            <div className="grid gap-2">{children}</div>
        </details>
    );
}
