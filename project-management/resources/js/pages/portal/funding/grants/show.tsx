import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
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
import { Field, invalid, Select } from '@/components/field';
import { formatMoney, toMajor } from '@/lib/money';
import { formatDate, statusLabel, statusTone } from '@/lib/status';
import { budget } from '@/routes/projects';
import { apply, award, close, decline, edit, index } from '@/routes/grants';
import {
    destroy as removeAllocation,
    store as allocate,
} from '@/routes/grants/allocations';
import { store as amend } from '@/routes/grants/amendments';
import {
    destroy as unschedule,
    receive,
    store as schedule,
} from '@/routes/grants/disbursements';
import {
    DonorPortalPanel,
    type GrantPortal,
} from '@/components/donor-portal-panel';
import {
    destroy as removeReport,
    status as moveReport,
    store as addReport,
} from '@/routes/grants/reports';
import type { Option } from '@/types';

type Grant = {
    id: string;
    reference: string;
    title: string;
    description: string | null;
    funder: string;
    status: string;
    pipeline: boolean;
    currency: string;
    amountMinor: number;
    restricted: boolean;
    startOn: string | null;
    endOn: string | null;
    appliedOn: string | null;
    awardedOn: string | null;
    closedOn: string | null;
    declinedOn: string | null;
};

type Props = {
    grant: Grant;
    totals: {
        allocatedMinor: number;
        availableMinor: number;
        scheduledMinor: number;
        receivedMinor: number;
        unscheduledMinor: number;
    };
    allocations: {
        id: string;
        projectId: string;
        project: string;
        amountMinor: number;
        exchangeRate: string;
        projectCurrency: string;
        projectAmountMinor: number;
        committedMinor: number;
        spentMinor: number;
        note: string | null;
    }[];
    disbursements: {
        id: string;
        dueOn: string;
        amountMinor: number;
        receivedOn: string | null;
        overdue: boolean;
        note: string | null;
    }[];
    reports: {
        id: string;
        title: string;
        kind: string;
        dueOn: string;
        status: string;
        next: Option[];
        submittedOn: string | null;
        acceptedOn: string | null;
        overdue: boolean;
        notes: string | null;
    }[];
    amendments: {
        id: string;
        number: number;
        effectiveOn: string;
        reason: string;
        amountBeforeMinor: number;
        amountAfterMinor: number;
        endBefore: string | null;
        endAfter: string | null;
        recordedBy: string;
    }[];
    projects: (Option & { currency: string })[];
    reportKinds: Option[];
    canManage: boolean;
    portal: GrantPortal;
    today: string;
};

const keep = { preserveScroll: true };

/** A form folded away behind a button until needed. */
function Reveal({
    label,
    variant = 'outline',
    children,
}: {
    label: string;
    variant?: 'outline' | 'default';
    children: (close: () => void) => ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return open ? (
        <div className="w-full">{children(() => setOpen(false))}</div>
    ) : (
        <Button size="sm" variant={variant} onClick={() => setOpen(true)}>
            {label}
        </Button>
    );
}

/**
 * One grant: its terms, where its money goes (allocations), when it
 * arrives (payments), what the funder expects (reports), and its
 * amendments. Stage actions move it from application to closeout.
 */
export default function GrantPage({
    grant,
    totals,
    allocations,
    disbursements,
    reports,
    amendments,
    projects,
    reportKinds,
    canManage,
    today,
    portal,
}: Props) {
    const { errors: pageErrors } = usePage<{
        errors: Record<string, string>;
    }>().props;
    const paymentError = pageErrors.disbursement ?? pageErrors.received_on;
    const money = (minor: number) => formatMoney(minor, grant.currency);
    const active = grant.status === 'active';
    const manageActive = canManage && active;
    const [projectId, setProjectId] = useState('');
    const chosenCurrency = projects.find(
        (project) => project.value === projectId,
    )?.currency;
    const needsRate =
        chosenCurrency !== undefined && chosenCurrency !== grant.currency;
    const post = (url: string, data: Record<string, string> = {}) =>
        router.post(url, data, keep);

    return (
        <>
            <Head title={grant.reference} />
            <PageHeader
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        {grant.reference}
                        <StatusBadge tone={statusTone(grant.status)}>
                            {statusLabel(grant.status)}
                        </StatusBadge>
                        {grant.restricted && (
                            <StatusBadge>Restricted</StatusBadge>
                        )}
                    </span>
                }
                description={`${grant.title} · ${grant.funder}`}
                actions={
                    <>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>← Grants</Link>
                        </Button>
                        {canManage && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={edit({ grant: grant.id })}>
                                    Edit
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            {canManage && (grant.pipeline || active) && (
                <Panel className="mb-4">
                    <PanelBody className="flex flex-wrap items-start gap-2">
                        {grant.status === 'opportunity' && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    post(apply.url({ grant: grant.id }), {
                                        on: today,
                                    })
                                }
                            >
                                Mark as applied today
                            </Button>
                        )}
                        {grant.pipeline && (
                            <>
                                <Reveal
                                    label="Record the award"
                                    variant="default"
                                >
                                    {(done) => (
                                        <Form
                                            {...award.form({ grant: grant.id })}
                                            options={keep}
                                            onSuccess={done}
                                            className="grid gap-3 sm:grid-cols-4"
                                        >
                                            {({ errors, processing }) => (
                                                <>
                                                    <Field
                                                        id="award_amount"
                                                        label={`Amount awarded (${grant.currency})`}
                                                        error={errors.amount}
                                                    >
                                                        <Input
                                                            id="award_amount"
                                                            name="amount"
                                                            inputMode="decimal"
                                                            required
                                                            {...invalid(
                                                                'award_amount',
                                                                errors.amount,
                                                            )}
                                                        />
                                                    </Field>
                                                    <Field
                                                        id="award_on"
                                                        label="Awarded on"
                                                        error={errors.on}
                                                    >
                                                        <Input
                                                            id="award_on"
                                                            name="on"
                                                            type="date"
                                                            max={today}
                                                            defaultValue={today}
                                                            required
                                                        />
                                                    </Field>
                                                    <Field
                                                        id="award_start"
                                                        label="Agreement starts"
                                                        error={errors.start_on}
                                                    >
                                                        <Input
                                                            id="award_start"
                                                            name="start_on"
                                                            type="date"
                                                            defaultValue={
                                                                grant.startOn ??
                                                                ''
                                                            }
                                                        />
                                                    </Field>
                                                    <Field
                                                        id="award_end"
                                                        label="Agreement ends"
                                                        error={errors.end_on}
                                                    >
                                                        <Input
                                                            id="award_end"
                                                            name="end_on"
                                                            type="date"
                                                            defaultValue={
                                                                grant.endOn ??
                                                                ''
                                                            }
                                                        />
                                                    </Field>
                                                    <div className="flex gap-2 sm:col-span-4">
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Record the award
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={done}
                                                        >
                                                            Cancel
                                                        </Button>
                                                    </div>
                                                </>
                                            )}
                                        </Form>
                                    )}
                                </Reveal>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        post(decline.url({ grant: grant.id }), {
                                            on: today,
                                        })
                                    }
                                >
                                    Declined by the funder
                                </Button>
                            </>
                        )}
                        {active && (
                            <>
                                <Reveal label="Record an amendment">
                                    {(done) => (
                                        <Form
                                            {...amend.form({ grant: grant.id })}
                                            options={keep}
                                            onSuccess={done}
                                            className="grid gap-3 sm:grid-cols-3"
                                        >
                                            {({ errors, processing }) => (
                                                <>
                                                    <Field
                                                        id="amend_amount"
                                                        label={`New amount (${grant.currency})`}
                                                        error={errors.amount}
                                                    >
                                                        <Input
                                                            id="amend_amount"
                                                            name="amount"
                                                            inputMode="decimal"
                                                            required
                                                            defaultValue={toMajor(
                                                                grant.amountMinor,
                                                                grant.currency,
                                                            )}
                                                            {...invalid(
                                                                'amend_amount',
                                                                errors.amount,
                                                            )}
                                                        />
                                                    </Field>
                                                    <Field
                                                        id="amend_end"
                                                        label="New end date"
                                                        error={errors.end_on}
                                                    >
                                                        <Input
                                                            id="amend_end"
                                                            name="end_on"
                                                            type="date"
                                                            defaultValue={
                                                                grant.endOn ??
                                                                ''
                                                            }
                                                        />
                                                    </Field>
                                                    <Field
                                                        id="amend_effective"
                                                        label="Effective on"
                                                        error={
                                                            errors.effective_on
                                                        }
                                                    >
                                                        <Input
                                                            id="amend_effective"
                                                            name="effective_on"
                                                            type="date"
                                                            defaultValue={today}
                                                            required
                                                        />
                                                    </Field>
                                                    <Field
                                                        id="amend_reason"
                                                        label="Reason"
                                                        error={errors.reason}
                                                        className="sm:col-span-3"
                                                    >
                                                        <Input
                                                            id="amend_reason"
                                                            name="reason"
                                                            required
                                                            {...invalid(
                                                                'amend_reason',
                                                                errors.reason,
                                                            )}
                                                        />
                                                    </Field>
                                                    <div className="flex gap-2 sm:col-span-3">
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Record amendment
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={done}
                                                        >
                                                            Cancel
                                                        </Button>
                                                    </div>
                                                </>
                                            )}
                                        </Form>
                                    )}
                                </Reveal>
                                <Form
                                    {...close.form({ grant: grant.id })}
                                    options={keep}
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="on"
                                                value={today}
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Close out the grant
                                            </Button>
                                            {errors.grant && (
                                                <p className="mt-1 text-sm text-destructive">
                                                    {errors.grant}
                                                </p>
                                            )}
                                        </>
                                    )}
                                </Form>
                            </>
                        )}
                    </PanelBody>
                </Panel>
            )}

            <div className="grid gap-3 sm:grid-cols-3">
                <StatCard
                    label={grant.pipeline ? 'Requested' : 'Amount'}
                    value={money(grant.amountMinor)}
                    hint={
                        grant.startOn || grant.endOn
                            ? `${grant.startOn ? formatDate(grant.startOn) : '…'} – ${grant.endOn ? formatDate(grant.endOn) : '…'}`
                            : undefined
                    }
                />
                <StatCard
                    label="Allocated to projects"
                    value={money(totals.allocatedMinor)}
                    hint={`${money(totals.availableMinor)} left to allocate`}
                    accent="violet"
                />
                <StatCard
                    label="Received"
                    value={money(totals.receivedMinor)}
                    hint={`of ${money(totals.scheduledMinor)} scheduled`}
                    accent="emerald"
                />
            </div>

            {grant.description && (
                <Panel className="mt-4">
                    <PanelBody className="text-sm whitespace-pre-line">
                        {grant.description}
                    </PanelBody>
                </Panel>
            )}

            <Panel className="mt-4">
                <PanelHeader
                    title="Projects funded"
                    description="Amounts in the grant's currency, and in each project's at the rate recorded; spending charged to the grant is in the project's currency."
                />
                {allocations.length > 0 && (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Project</TableHead>
                                <TableHead className="text-right">
                                    Allocated
                                </TableHead>
                                <TableHead className="text-right">
                                    In project currency
                                </TableHead>
                                <TableHead className="text-right">
                                    Committed
                                </TableHead>
                                <TableHead className="text-right">
                                    Spent
                                </TableHead>
                                {manageActive && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {allocations.map((allocation) => (
                                <TableRow key={allocation.id}>
                                    <TableCell>
                                        <Link
                                            href={budget({
                                                project: allocation.projectId,
                                            })}
                                            className="font-medium hover:underline"
                                        >
                                            {allocation.project}
                                        </Link>
                                        {allocation.note && (
                                            <p className="text-xs text-muted-foreground">
                                                {allocation.note}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(allocation.amountMinor)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            allocation.projectAmountMinor,
                                            allocation.projectCurrency,
                                        )}
                                        {allocation.projectCurrency !==
                                            grant.currency && (
                                            <p className="text-xs text-muted-foreground">
                                                at {allocation.exchangeRate}{' '}
                                                {allocation.projectCurrency}/
                                                {grant.currency}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            allocation.committedMinor,
                                            allocation.projectCurrency,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            allocation.spentMinor,
                                            allocation.projectCurrency,
                                        )}
                                    </TableCell>
                                    {manageActive && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label="Remove allocation"
                                                onClick={() =>
                                                    router.delete(
                                                        removeAllocation.url({
                                                            grant: grant.id,
                                                            allocation:
                                                                allocation.id,
                                                        }),
                                                        keep,
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
                {manageActive && projects.length > 0 ? (
                    <PanelBody
                        className={allocations.length > 0 ? 'border-t' : ''}
                    >
                        <Form
                            {...allocate.form({ grant: grant.id })}
                            options={keep}
                            resetOnSuccess
                            onSuccess={() => setProjectId('')}
                            className="grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="project_id"
                                        label="Project"
                                        error={errors.project_id}
                                    >
                                        <Select
                                            id="project_id"
                                            name="project_id"
                                            value={projectId}
                                            onChange={(event) =>
                                                setProjectId(event.target.value)
                                            }
                                            placeholder="Choose a project"
                                            options={projects}
                                        />
                                    </Field>
                                    <Field
                                        id="allocation_amount"
                                        label={`Amount (${grant.currency})`}
                                        error={errors.amount}
                                    >
                                        <Input
                                            id="allocation_amount"
                                            name="amount"
                                            inputMode="decimal"
                                            required
                                            {...invalid(
                                                'allocation_amount',
                                                errors.amount,
                                            )}
                                        />
                                    </Field>
                                    {needsRate ? (
                                        <Field
                                            id="exchange_rate"
                                            label={`${chosenCurrency} per 1 ${grant.currency}`}
                                            error={errors.exchange_rate}
                                        >
                                            <Input
                                                id="exchange_rate"
                                                name="exchange_rate"
                                                inputMode="decimal"
                                                required
                                                {...invalid(
                                                    'exchange_rate',
                                                    errors.exchange_rate,
                                                )}
                                            />
                                        </Field>
                                    ) : (
                                        <span />
                                    )}
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Allocate
                                    </Button>
                                    {errors.grant && (
                                        <p className="text-sm text-destructive sm:col-span-4">
                                            {errors.grant}
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                    </PanelBody>
                ) : (
                    allocations.length === 0 && (
                        <p className="px-3 py-3 text-sm text-muted-foreground">
                            {active
                                ? 'Not allocated to a project yet.'
                                : 'Allocated once the grant is awarded.'}
                        </p>
                    )
                )}
            </Panel>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Panel>
                    <PanelHeader
                        title="Payments"
                        description={
                            active
                                ? `${money(totals.unscheduledMinor)} not yet scheduled.`
                                : undefined
                        }
                    />
                    {paymentError && (
                        <p
                            role="alert"
                            className="mb-3 border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                        >
                            {paymentError}
                        </p>
                    )}
                    {disbursements.length === 0 && !manageActive && (
                        <p className="px-3 py-3 text-sm text-muted-foreground">
                            No payments scheduled.
                        </p>
                    )}
                    <ul className="divide-y">
                        {disbursements.map((payment) => (
                            <li
                                key={payment.id}
                                className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm"
                            >
                                <span className="flex-1">
                                    <span
                                        className={
                                            payment.overdue
                                                ? 'text-destructive'
                                                : undefined
                                        }
                                    >
                                        {formatDate(payment.dueOn)}
                                    </span>
                                    {payment.note && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {payment.note}
                                        </span>
                                    )}
                                </span>
                                <span className="tabular-nums">
                                    {money(payment.amountMinor)}
                                </span>
                                {payment.receivedOn ? (
                                    <span className="flex items-center gap-2">
                                        <StatusBadge tone="success">
                                            Received{' '}
                                            {formatDate(payment.receivedOn)}
                                        </StatusBadge>
                                    </span>
                                ) : (
                                    manageActive && (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    post(
                                                        receive.url({
                                                            grant: grant.id,
                                                            disbursement:
                                                                payment.id,
                                                        }),
                                                        { on: today },
                                                    )
                                                }
                                            >
                                                Received today
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                aria-label="Remove payment"
                                                onClick={() =>
                                                    router.delete(
                                                        unschedule.url({
                                                            grant: grant.id,
                                                            disbursement:
                                                                payment.id,
                                                        }),
                                                        keep,
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </>
                                    )
                                )}
                            </li>
                        ))}
                    </ul>
                    {manageActive && totals.unscheduledMinor > 0 && (
                        <PanelBody className="border-t">
                            <Form
                                {...schedule.form({ grant: grant.id })}
                                options={keep}
                                resetOnSuccess
                                className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="due_on"
                                            label="Due"
                                            error={errors.due_on}
                                        >
                                            <Input
                                                id="due_on"
                                                name="due_on"
                                                type="date"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            id="payment_amount"
                                            label={`Amount (${grant.currency})`}
                                            error={errors.amount}
                                        >
                                            <Input
                                                id="payment_amount"
                                                name="amount"
                                                inputMode="decimal"
                                                required
                                                {...invalid(
                                                    'payment_amount',
                                                    errors.amount,
                                                )}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            Schedule
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    )}
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Reports to the funder"
                        description="A grant closes only when none is owed."
                    />
                    {reports.length === 0 && (
                        <p className="px-3 py-3 text-sm text-muted-foreground">
                            No reporting requirements recorded.
                        </p>
                    )}
                    <ul className="divide-y">
                        {reports.map((report) => (
                            <li key={report.id} className="px-3 py-2 text-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="flex-1 font-medium">
                                        {report.title}
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            · {report.kind}
                                        </span>
                                    </span>
                                    <StatusBadge
                                        tone={statusTone(report.status)}
                                    >
                                        {statusLabel(report.status)}
                                    </StatusBadge>
                                </div>
                                <p
                                    className={
                                        report.overdue
                                            ? 'text-xs text-destructive'
                                            : 'text-xs text-muted-foreground'
                                    }
                                >
                                    {report.overdue ? 'Was due' : 'Due'}{' '}
                                    {formatDate(report.dueOn)}
                                    {report.submittedOn &&
                                        ` · submitted ${formatDate(report.submittedOn)}`}
                                    {report.acceptedOn &&
                                        ` · accepted ${formatDate(report.acceptedOn)}`}
                                </p>
                                {canManage && report.next.length > 0 && (
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {report.next.map((next) => (
                                            <Button
                                                key={next.value}
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    post(
                                                        moveReport.url({
                                                            grant: grant.id,
                                                            report: report.id,
                                                        }),
                                                        {
                                                            status: next.value,
                                                            on: today,
                                                        },
                                                    )
                                                }
                                            >
                                                {next.label}
                                            </Button>
                                        ))}
                                        {report.status === 'due' &&
                                            !report.submittedOn && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    aria-label="Remove requirement"
                                                    onClick={() =>
                                                        router.delete(
                                                            removeReport.url({
                                                                grant: grant.id,
                                                                report: report.id,
                                                            }),
                                                            keep,
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                    {canManage && (active || grant.status === 'applied') && (
                        <PanelBody className="border-t">
                            <Form
                                {...addReport.form({ grant: grant.id })}
                                options={keep}
                                resetOnSuccess
                                className="grid gap-3 sm:grid-cols-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="report_title"
                                            label="Report"
                                            error={errors.title}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="report_title"
                                                name="title"
                                                placeholder="Interim narrative report"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            id="report_kind"
                                            label="Kind"
                                            error={errors.kind}
                                        >
                                            <Select
                                                id="report_kind"
                                                name="kind"
                                                defaultValue="combined"
                                                options={reportKinds}
                                            />
                                        </Field>
                                        <Field
                                            id="report_due"
                                            label="Due"
                                            error={errors.due_on}
                                        >
                                            <Input
                                                id="report_due"
                                                name="due_on"
                                                type="date"
                                                required
                                            />
                                        </Field>
                                        <div className="sm:col-span-2">
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Add requirement
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    )}
                </Panel>
            </div>

            {amendments.length > 0 && (
                <Panel className="mt-4">
                    <PanelHeader
                        title="Amendments"
                        description="A record of every change to the awarded amount or end date."
                    />
                    <ul className="divide-y">
                        {amendments.map((amendment) => (
                            <li
                                key={amendment.id}
                                className="px-3 py-2 text-sm"
                            >
                                <span className="font-medium">
                                    No. {amendment.number}
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    effective{' '}
                                    {formatDate(amendment.effectiveOn)} ·{' '}
                                    {amendment.recordedBy}
                                </span>
                                <p>
                                    {money(amendment.amountBeforeMinor)} →{' '}
                                    {money(amendment.amountAfterMinor)}
                                    {amendment.endBefore !==
                                        amendment.endAfter && (
                                        <>
                                            {' · ends '}
                                            {amendment.endBefore
                                                ? formatDate(
                                                      amendment.endBefore,
                                                  )
                                                : 'open'}{' '}
                                            →{' '}
                                            {amendment.endAfter
                                                ? formatDate(amendment.endAfter)
                                                : 'open'}
                                        </>
                                    )}
                                </p>
                                <p className="text-muted-foreground">
                                    {amendment.reason}
                                </p>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

            <DonorPortalPanel
                grantId={grant.id}
                visible={grant.status === 'active' || grant.status === 'closed'}
                canManage={canManage}
                portal={portal}
            />

            <p className="mt-4 text-xs text-muted-foreground">
                {grant.appliedOn && `Applied ${formatDate(grant.appliedOn)}. `}
                {grant.awardedOn && `Awarded ${formatDate(grant.awardedOn)}. `}
                {grant.declinedOn &&
                    `Declined ${formatDate(grant.declinedOn)}. `}
                {grant.closedOn && `Closed ${formatDate(grant.closedOn)}.`}
            </p>
        </>
    );
}
