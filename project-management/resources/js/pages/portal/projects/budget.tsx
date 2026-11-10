import { Form, Link, router, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
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
import { cn } from '@miles/ui/lib/utils';
import { Field, invalid, Select } from '@/components/field';
import { ProjectFrame } from '@/components/project-frame';
import { formatMoney } from '@/lib/money';
import { formatDateTime, statusLabel, statusTone } from '@/lib/status';
import { show as showGrant } from '@/routes/grants';
import { exportMethod as exportBudget } from '@/routes/projects/budget';
import {
    destroy as removeLine,
    store as addLine,
} from '@/routes/projects/budget/lines';
import {
    approve as approveVersion,
    destroy as discardVersion,
    returnMethod as returnVersion,
    store as reviseBudget,
    submit as submitVersion,
} from '@/routes/projects/budget/versions';
import type { Option, ProjectHeader } from '@/types';

type Version = {
    id: string;
    number: number;
    status: 'draft' | 'submitted' | 'approved' | 'superseded';
    statusLabel: string;
    totalMinor: number;
    note: string | null;
    returnedReason: string | null;
    submittedBy: string | null;
    submittedAt: string | null;
    approvedBy: string | null;
    approvedAt: string | null;
    mine: boolean;
    lines: {
        id: string;
        category: string;
        description: string | null;
        amountMinor: number;
    }[];
};

type Props = {
    project: ProjectHeader;
    budgetMinor: number;
    basis: 'approved' | 'draft' | 'none';
    actualMinor: number;
    committedMinor: number;
    byCategory: {
        category: string;
        label: string;
        budgetMinor: number;
        committedMinor: number;
        actualMinor: number;
        availableMinor: number;
    }[];
    versions: Version[];
    funding: {
        id: string;
        grantId: string;
        reference: string;
        title: string;
        funder: string;
        status: string;
        grantCurrency: string;
        amountMinor: number;
        projectAmountMinor: number;
        committedMinor: number;
        spentMinor: number;
    }[];
    canViewGrants: boolean;
    canApprove: boolean;
    categories: Option[];
};

/**
 * The budget, by version, against what is committed (approved, not yet
 * paid) and spent. Labour is time logged at each person's cost; the rest
 * is spending (the Spending tab).
 */
export default function Budget({
    project,
    budgetMinor,
    basis,
    actualMinor,
    committedMinor,
    byCategory,
    versions,
    funding,
    canViewGrants,
    canApprove,
    categories,
}: Props) {
    const money = (minor: number) => formatMoney(minor, project.currency);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const available = budgetMinor - committedMinor - actualMinor;
    const approved = versions.find((version) => version.status === 'approved');
    const working = versions.find(
        (version) =>
            version.status === 'draft' || version.status === 'submitted',
    );

    return (
        <ProjectFrame project={project} title="Budget">
            <p className="mb-4 text-right">
                <a
                    href={exportBudget.url({ project: project.id })}
                    className="text-sm underline"
                >
                    Download CSV
                </a>
            </p>
            {errors.budget && (
                <p
                    role="alert"
                    className="mb-4 border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                >
                    {errors.budget}
                </p>
            )}

            <div className="grid gap-3 sm:grid-cols-4">
                <StatCard
                    label="Budget"
                    value={money(budgetMinor)}
                    hint={
                        basis === 'approved'
                            ? `Version ${approved?.number}, approved`
                            : basis === 'draft'
                              ? 'Not approved yet'
                              : undefined
                    }
                    accent={basis === 'approved' ? 'blue' : 'slate'}
                />
                <StatCard
                    label="Committed"
                    value={money(committedMinor)}
                    hint="Approved, not paid"
                    accent="amber"
                />
                <StatCard
                    label="Spent"
                    value={money(actualMinor)}
                    hint={
                        budgetMinor > 0
                            ? `${Math.round((actualMinor / budgetMinor) * 100)}% of budget`
                            : undefined
                    }
                    accent="violet"
                />
                <StatCard
                    label={available < 0 ? 'Over budget' : 'Available'}
                    value={money(Math.abs(available))}
                    accent={available < 0 ? 'red' : 'emerald'}
                />
            </div>

            <Panel className="mt-4">
                <PanelHeader
                    title="By category"
                    description={
                        basis === 'draft'
                            ? 'Against the draft: spending is approved only against an approved budget.'
                            : undefined
                    }
                />
                {byCategory.length === 0 ? (
                    <p className="px-3 py-3 text-sm text-muted-foreground">
                        No budget or spending yet.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Category</TableHead>
                                <TableHead className="hidden sm:table-cell" />
                                <TableHead className="text-right">
                                    Budget
                                </TableHead>
                                <TableHead className="text-right">
                                    Committed
                                </TableHead>
                                <TableHead className="text-right">
                                    Spent
                                </TableHead>
                                <TableHead className="text-right">
                                    Available
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {byCategory.map((row) => {
                                const used =
                                    row.committedMinor + row.actualMinor;
                                const share =
                                    row.budgetMinor > 0
                                        ? (used / row.budgetMinor) * 100
                                        : used > 0
                                          ? 100
                                          : 0;

                                return (
                                    <TableRow key={row.category}>
                                        <TableCell className="font-medium">
                                            {row.label}
                                        </TableCell>
                                        <TableCell className="hidden w-1/4 sm:table-cell">
                                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className={cn(
                                                        'h-full rounded-full',
                                                        row.availableMinor < 0
                                                            ? 'bg-destructive'
                                                            : 'bg-primary',
                                                    )}
                                                    style={{
                                                        width: `${Math.min(100, share)}%`,
                                                    }}
                                                />
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(row.budgetMinor)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(row.committedMinor)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(row.actualMinor)}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right tabular-nums',
                                                row.availableMinor < 0 &&
                                                    'text-destructive',
                                            )}
                                        >
                                            {money(row.availableMinor)}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            <FundingPanel
                funding={funding}
                budgetMinor={budgetMinor}
                currency={project.currency}
                canViewGrants={canViewGrants}
            />

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <WorkingVersion
                    project={project}
                    working={working}
                    approved={approved}
                    canApprove={canApprove}
                    categories={categories}
                />
                {approved ? (
                    <Panel>
                        <PanelHeader
                            title={`Approved budget: version ${approved.number}`}
                            description={[
                                approved.approvedBy &&
                                    `Approved by ${approved.approvedBy}`,
                                approved.approvedAt &&
                                    formatDateTime(approved.approvedAt),
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        />
                        <Lines version={approved} money={money} />
                    </Panel>
                ) : (
                    <Panel>
                        <PanelHeader title="Approved budget" />
                        <p className="px-3 py-3 text-sm text-muted-foreground">
                            None yet. Spending can be requested, but it is
                            approved only against an approved budget.
                        </p>
                    </Panel>
                )}
            </div>

            {versions.length > 0 && (
                <Panel className="mt-4">
                    <PanelHeader
                        title="History"
                        description="Every version, newest first. Approved versions never change: a change is a new version."
                    />
                    <ul className="divide-y">
                        {versions.map((version) => (
                            <li
                                key={version.id}
                                className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm"
                            >
                                <span className="w-24 font-medium">
                                    Version {version.number}
                                </span>
                                <StatusBadge tone={statusTone(version.status)}>
                                    {version.statusLabel}
                                </StatusBadge>
                                <span className="flex-1 text-xs text-muted-foreground">
                                    {[
                                        version.submittedBy &&
                                            `submitted by ${version.submittedBy}`,
                                        version.approvedBy &&
                                            `approved by ${version.approvedBy}`,
                                        version.approvedAt &&
                                            formatDateTime(version.approvedAt),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                                <span className="tabular-nums">
                                    {money(version.totalMinor)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </ProjectFrame>
    );
}

/**
 * The version being worked on: its lines (changed only in a draft), and
 * submitting, approving or returning it.
 */
function WorkingVersion({
    project,
    working,
    approved,
    canApprove,
    categories,
}: {
    project: ProjectHeader;
    working: Version | undefined;
    approved: Version | undefined;
    canApprove: boolean;
    categories: Option[];
}) {
    const money = (minor: number) => formatMoney(minor, project.currency);
    const manage = project.can.manage;
    const draft = working?.status === 'draft';
    const editable = manage && (working === undefined ? !approved : draft);
    const ids = (version: Version) => ({
        project: project.id,
        budgetVersion: version.id,
    });

    return (
        <Panel>
            <PanelHeader
                title={
                    working
                        ? `Version ${working.number}`
                        : approved
                          ? 'Changing the budget'
                          : 'Budget lines'
                }
                description={
                    working
                        ? working.status === 'submitted'
                            ? `Awaiting approval${working.submittedBy ? `, submitted by ${working.submittedBy}` : ''}.`
                            : 'Draft: lines can change until it is submitted.'
                        : approved
                          ? 'A revision starts from the approved lines.'
                          : 'Add the lines, then submit the budget for approval.'
                }
                actions={
                    working && (
                        <StatusBadge tone={statusTone(working.status)}>
                            {statusLabel(working.status)}
                        </StatusBadge>
                    )
                }
            />

            {working?.returnedReason && draft && (
                <p className="border-b bg-brand-50 px-3 py-2 text-sm text-brand-900 dark:bg-brand-950/30 dark:text-brand-200">
                    Returned: {working.returnedReason}
                </p>
            )}
            {working?.note && (
                <p className="border-b px-3 py-2 text-sm text-muted-foreground">
                    {working.note}
                </p>
            )}

            {working && (
                <Lines
                    version={working}
                    money={money}
                    onRemove={
                        manage && draft
                            ? (lineId) =>
                                  router.delete(
                                      removeLine.url({
                                          project: project.id,
                                          budgetLine: lineId,
                                      }),
                                      { preserveScroll: true },
                                  )
                            : undefined
                    }
                />
            )}

            {manage && !working && approved && (
                <PanelBody>
                    <Form
                        {...reviseBudget.form({ project: project.id })}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button type="submit" disabled={processing}>
                                Start a revision
                            </Button>
                        )}
                    </Form>
                </PanelBody>
            )}

            {editable && (
                <PanelBody className={cn(working && 'border-t')}>
                    <Form
                        {...addLine.form({ project: project.id })}
                        resetOnSuccess={['description', 'amount']}
                        options={{ preserveScroll: true }}
                        className="grid gap-3 sm:grid-cols-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="line-category"
                                    label="Category"
                                    error={errors.category}
                                >
                                    <Select
                                        id="line-category"
                                        name="category"
                                        options={categories}
                                    />
                                </Field>
                                <Field
                                    id="line-amount"
                                    label="Amount"
                                    error={errors.amount}
                                >
                                    <Input
                                        id="line-amount"
                                        name="amount"
                                        required
                                        inputMode="decimal"
                                        {...invalid(
                                            'line-amount',
                                            errors.amount,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="line-description"
                                    label="Description"
                                    error={errors.description}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="line-description"
                                        name="description"
                                    />
                                </Field>
                                <div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Add line
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            )}

            {manage && working && draft && (
                <PanelBody className="flex flex-wrap items-end gap-3 border-t">
                    <Form
                        {...submitVersion.form(ids(working))}
                        options={{ preserveScroll: true }}
                        className="flex flex-1 flex-wrap items-end gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="submit-note"
                                    label="Note for the approver"
                                    error={errors.note}
                                    className="min-w-48 flex-1"
                                >
                                    <Input id="submit-note" name="note" />
                                </Field>
                                <Button
                                    type="submit"
                                    disabled={
                                        processing || working.lines.length === 0
                                    }
                                >
                                    Submit for approval
                                </Button>
                            </>
                        )}
                    </Form>
                    <Button
                        variant="ghost"
                        onClick={() =>
                            router.delete(discardVersion.url(ids(working)), {
                                preserveScroll: true,
                            })
                        }
                    >
                        Discard draft
                    </Button>
                </PanelBody>
            )}

            {working?.status === 'submitted' && canApprove && (
                <PanelBody className="grid gap-3 border-t">
                    {working.mine ? (
                        <p className="text-sm text-muted-foreground">
                            You submitted it: someone else approves it.
                        </p>
                    ) : (
                        <>
                            <Form
                                {...approveVersion.form(ids(working))}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button type="submit" disabled={processing}>
                                        Approve version {working.number}
                                    </Button>
                                )}
                            </Form>
                            <Form
                                {...returnVersion.form(ids(working))}
                                options={{ preserveScroll: true }}
                                className="flex flex-wrap items-end gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="return-reason"
                                            label="Or return it, saying why"
                                            error={errors.reason}
                                            className="min-w-48 flex-1"
                                        >
                                            <Input
                                                id="return-reason"
                                                name="reason"
                                                required
                                                {...invalid(
                                                    'return-reason',
                                                    errors.reason,
                                                )}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Return to draft
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </>
                    )}
                </PanelBody>
            )}
        </Panel>
    );
}

function Lines({
    version,
    money,
    onRemove,
}: {
    version: Version;
    money: (minor: number) => string;
    onRemove?: (lineId: string) => void;
}) {
    if (version.lines.length === 0) {
        return (
            <p className="px-3 py-3 text-sm text-muted-foreground">
                No lines yet.
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {version.lines.map((line) => (
                <li
                    key={line.id}
                    className="flex items-center gap-2 px-3 py-2 text-sm"
                >
                    <span className="flex-1">
                        {line.category}
                        {line.description && (
                            <span className="text-muted-foreground">
                                {' '}
                                · {line.description}
                            </span>
                        )}
                    </span>
                    <span className="tabular-nums">
                        {money(line.amountMinor)}
                    </span>
                    {onRemove && (
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label="Remove line"
                            onClick={() => onRemove(line.id)}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    )}
                </li>
            ))}
            <li className="flex items-center gap-2 px-3 py-2 text-sm font-medium">
                <span className="flex-1">Total</span>
                <span className="tabular-nums">
                    {money(version.totalMinor)}
                </span>
                {onRemove && <span className="w-9" />}
            </li>
        </ul>
    );
}

/**
 * The grants paying for the project, in its currency at the rate recorded
 * on each allocation; how much of the budget they cover, and what is
 * committed and spent against each.
 */
function FundingPanel({
    funding,
    budgetMinor,
    currency,
    canViewGrants,
}: {
    funding: Props['funding'];
    budgetMinor: number;
    currency: string;
    canViewGrants: boolean;
}) {
    const money = (minor: number) => formatMoney(minor, currency);
    const fundedMinor = funding.reduce(
        (sum, row) => sum + row.projectAmountMinor,
        0,
    );
    const gapMinor = budgetMinor - fundedMinor;

    return (
        <Panel className="mt-4">
            <PanelHeader
                title="Funding"
                description={
                    funding.length === 0
                        ? 'No grant is allocated to this project yet.'
                        : gapMinor > 0
                          ? `${money(fundedMinor)} funded: ${money(gapMinor)} of the budget is not covered by a grant.`
                          : `${money(fundedMinor)} funded: the budget is covered.`
                }
            />
            {funding.length > 0 && (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Grant</TableHead>
                            <TableHead />
                            <TableHead className="text-right">
                                Allocated
                            </TableHead>
                            <TableHead className="text-right">
                                Committed
                            </TableHead>
                            <TableHead className="text-right">Spent</TableHead>
                            <TableHead className="text-right">Left</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {funding.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell>
                                    {canViewGrants ? (
                                        <Link
                                            href={showGrant({
                                                grant: row.grantId,
                                            })}
                                            className="font-medium hover:underline"
                                        >
                                            {row.reference}
                                        </Link>
                                    ) : (
                                        <span className="font-medium">
                                            {row.reference}
                                        </span>
                                    )}
                                    <span className="block text-xs text-muted-foreground">
                                        {row.funder}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    <StatusBadge tone={statusTone(row.status)}>
                                        {statusLabel(row.status)}
                                    </StatusBadge>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(row.projectAmountMinor)}
                                    {row.grantCurrency !== currency && (
                                        <span className="block text-xs text-muted-foreground">
                                            {formatMoney(
                                                row.amountMinor,
                                                row.grantCurrency,
                                            )}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(row.committedMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(row.spentMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(
                                        row.projectAmountMinor -
                                            row.committedMinor -
                                            row.spentMinor,
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </Panel>
    );
}
