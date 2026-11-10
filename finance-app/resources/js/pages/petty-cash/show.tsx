import { Form, Head, Link } from '@inertiajs/react';
import { Check, Paperclip, X } from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { Field, Select } from '@/components/field';
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
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useMoney } from '@/lib/format';
import { show as journalRoute } from '@/routes/journals';
import {
    close,
    custodian,
    receipt,
    replenish,
    update,
} from '@/routes/petty-cash';
import { store as storeCount } from '@/routes/petty-cash/counts';
import {
    reject,
    store as storeVoucher,
    verify,
} from '@/routes/petty-cash/vouchers';
import type { FundSummary } from './index';
import { FUND_STATUS, ToDo } from './index';

type Option = { id: string; label: string };

type Voucher = {
    id: string;
    number: string;
    paidOn: string;
    amountMinor: number;
    payee: string | null;
    description: string;
    account: string;
    costCentre: string | null;
    project: string | null;
    status: 'recorded' | 'verified' | 'rejected';
    recordedBy: string;
    checkedBy: string | null;
    rejectNote: string | null;
    replenishment: string | null;
    journalId: string;
    journal: string;
    receiptName: string;
    canCheck: boolean;
};

type Props = {
    fund: FundSummary;
    vouchers: Voucher[];
    counts: {
        id: string;
        countedOn: string;
        countedMinor: number;
        bookMinor: number;
        differenceMinor: number;
        note: string | null;
        by: string;
    }[];
    banks: Option[];
    accounts: (Option & { requires: string[] })[];
    dimensions: { costCentres: Option[]; projects: Option[] };
    can: { pay: boolean; replenish: boolean; count: boolean; manage: boolean };
    isCustodian: boolean;
    limit: string | null;
    today: string;
};

const VOUCHER_STATUS = {
    recorded: { label: 'To check', tone: 'warning' },
    verified: { label: 'Verified', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'danger' },
} as const;

/**
 * One fund: what it holds, its vouchers and counts, and what the member may
 * do with it (the server checks each step again).
 */
export default function PettyCashFund({
    fund,
    vouchers,
    counts,
    banks,
    accounts,
    dimensions,
    can,
    isCustodian,
    limit,
    today,
}: Props) {
    const money = useMoney(fund.currency);

    return (
        <>
            <Head title={fund.name} />
            <PageHeader
                title={fund.name}
                description={
                    fund.custodian === null
                        ? `${fund.account}. ${fund.movedIn ? 'Moved in from Projects. ' : ''}No custodian yet: count the cash, then hand it to one.`
                        : `${fund.account}. Kept by ${fund.custodian}${isCustodian ? ' (you)' : ''}.`
                }
            />

            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <StatCard
                    label="Holds"
                    value={money(fund.heldMinor)}
                    hint={`Float ${money(fund.floatMinor)}${fund.replenishAtMinor === null ? '' : ` · top up at ${money(fund.replenishAtMinor)}`}`}
                    accent={fund.due ? 'amber' : 'emerald'}
                />
                <StatCard
                    label="Status"
                    value={FUND_STATUS[fund.status].label}
                    hint={<ToDo fund={fund} />}
                    accent="blue"
                />
                <StatCard
                    label="Spent since the last top-up"
                    value={money(Math.max(fund.floatMinor - fund.heldMinor, 0))}
                    hint={
                        limit === null
                            ? 'No petty cash limit set yet'
                            : `Up to ${limit} a voucher`
                    }
                    accent="violet"
                />
            </div>

            {can.pay && (
                <PayPanel
                    fund={fund}
                    accounts={accounts}
                    dimensions={dimensions}
                    today={today}
                />
            )}

            <Panel className="mb-3">
                <PanelHeader
                    title="Vouchers"
                    description="Posted when recorded. Someone other than the custodian checks each one; a rejected voucher is reversed and the custodian owes the fund."
                />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Voucher</TableHead>
                            <TableHead>Paid</TableHead>
                            <TableHead>For</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {vouchers.length === 0 && (
                            <TableEmpty colSpan={6}>
                                No vouchers yet.
                            </TableEmpty>
                        )}
                        {vouchers.map((voucher) => (
                            <VoucherRow
                                key={voucher.id}
                                fundId={fund.id}
                                voucher={voucher}
                                currency={fund.currency}
                            />
                        ))}
                    </TableBody>
                </Table>
            </Panel>

            <div className="grid gap-3 lg:grid-cols-2">
                {can.replenish && (
                    <ReplenishPanel fund={fund} banks={banks} today={today} />
                )}
                {can.count && <CountPanel fund={fund} />}
            </div>

            <CountsPanel counts={counts} currency={fund.currency} />

            {can.manage && <ManagePanel fund={fund} />}
        </>
    );
}

function PayPanel({
    fund,
    accounts,
    dimensions,
    today,
}: {
    fund: FundSummary;
    accounts: Props['accounts'];
    dimensions: Props['dimensions'];
    today: string;
}) {
    const [accountId, setAccountId] = useState('');
    const requires =
        accounts.find((account) => account.id === accountId)?.requires ?? [];

    return (
        <Panel className="mb-3">
            <PanelHeader
                title="Record a payment"
                description="Every payment needs its receipt. It cannot be more than the fund holds or the petty cash limit."
            />
            <PanelBody>
                <Form
                    {...storeVoucher.form(fund.id)}
                    resetOnSuccess
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                id="paid_on"
                                label="Paid on"
                                error={errors.paid_on}
                            >
                                <Input
                                    id="paid_on"
                                    name="paid_on"
                                    type="date"
                                    max={today}
                                    defaultValue={today}
                                    required
                                />
                            </Field>
                            <Field
                                id="amount"
                                label={`Amount${fund.currency ? ` (${fund.currency})` : ''}`}
                                error={errors.amount ?? errors.fund}
                            >
                                <Input
                                    id="amount"
                                    name="amount"
                                    inputMode="decimal"
                                    className="text-right tabular-nums"
                                    required
                                />
                            </Field>
                            <Field
                                id="payee"
                                label="Paid to"
                                error={errors.payee}
                            >
                                <Input id="payee" name="payee" />
                            </Field>
                            <Field
                                id="description"
                                label="What for"
                                error={errors.description}
                            >
                                <Input
                                    id="description"
                                    name="description"
                                    required
                                />
                            </Field>
                            <Field
                                id="account_id"
                                label="Spent on"
                                error={errors.account_id}
                            >
                                <Select
                                    id="account_id"
                                    name="account_id"
                                    required
                                    value={accountId}
                                    onChange={(event) =>
                                        setAccountId(event.target.value)
                                    }
                                >
                                    <option value="">Choose an account…</option>
                                    {accounts.map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                        >
                                            {account.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field
                                id="cost_centre_id"
                                label={`Cost centre${requires.includes('cost_centre') ? '' : ' (optional)'}`}
                                error={errors.cost_centre_id}
                            >
                                <Select
                                    id="cost_centre_id"
                                    name="cost_centre_id"
                                    required={requires.includes('cost_centre')}
                                >
                                    <option value="">None</option>
                                    {dimensions.costCentres.map((value) => (
                                        <option key={value.id} value={value.id}>
                                            {value.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field
                                id="project_id"
                                label={`Project${requires.includes('project') ? '' : ' (optional)'}`}
                                error={errors.project_id}
                            >
                                <Select
                                    id="project_id"
                                    name="project_id"
                                    required={requires.includes('project')}
                                >
                                    <option value="">None</option>
                                    {dimensions.projects.map((value) => (
                                        <option key={value.id} value={value.id}>
                                            {value.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field
                                id="receipt"
                                label="Receipt"
                                error={errors.receipt}
                                hint="A photo or PDF, up to 5 MB."
                            >
                                <Input
                                    id="receipt"
                                    name="receipt"
                                    type="file"
                                    accept="image/*,application/pdf"
                                    required
                                />
                            </Field>
                            <div className="sm:col-span-2 lg:col-span-4">
                                <Button type="submit" disabled={processing}>
                                    Record payment
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

function VoucherRow({
    fundId,
    voucher,
    currency,
}: {
    fundId: string;
    voucher: Voucher;
    currency: string | null;
}) {
    const [rejecting, setRejecting] = useState(false);

    return (
        <TableRow>
            <TableCell className="font-mono text-xs">
                <Link
                    href={journalRoute(voucher.journalId)}
                    className="text-primary hover:underline"
                >
                    {voucher.number}
                </Link>
                <a
                    href={receipt.url({ fund: fundId, voucher: voucher.id })}
                    className="mt-1 flex items-center gap-1 text-muted-foreground hover:underline"
                >
                    <Paperclip className="size-3" aria-hidden />
                    Receipt
                </a>
            </TableCell>
            <TableCell className="text-xs tabular-nums">
                {voucher.paidOn}
                <div className="text-muted-foreground">
                    by {voucher.recordedBy}
                </div>
            </TableCell>
            <TableCell>
                {voucher.description}
                <div className="text-xs text-muted-foreground">
                    {[
                        voucher.payee,
                        voucher.account,
                        voucher.costCentre,
                        voucher.project,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </div>
            </TableCell>
            <TableCell className="text-right">
                <Amount minor={voucher.amountMinor} currency={currency} />
            </TableCell>
            <TableCell>
                <StatusBadge tone={VOUCHER_STATUS[voucher.status].tone}>
                    {VOUCHER_STATUS[voucher.status].label}
                </StatusBadge>
                <div className="text-xs text-muted-foreground">
                    {voucher.checkedBy && `by ${voucher.checkedBy}`}
                    {voucher.rejectNote && `: ${voucher.rejectNote}`}
                    {voucher.replenishment &&
                        ` · paid back by ${voucher.replenishment}`}
                </div>
            </TableCell>
            <TableCell className="text-right">
                {voucher.canCheck && !rejecting && (
                    <div className="flex justify-end gap-1">
                        <Form
                            {...verify.form({
                                fund: fundId,
                                voucher: voucher.id,
                            })}
                        >
                            {({ processing }) => (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    <Check className="size-4" aria-hidden />
                                    Verify
                                </Button>
                            )}
                        </Form>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setRejecting(true)}
                        >
                            <X className="size-4" aria-hidden />
                            Reject
                        </Button>
                    </div>
                )}
                {rejecting && (
                    <Form
                        {...reject.form({ fund: fundId, voucher: voucher.id })}
                        className="flex justify-end gap-1"
                        onSuccess={() => setRejecting(false)}
                    >
                        {({ processing, errors }) => (
                            <>
                                <Input
                                    name="note"
                                    placeholder="Why"
                                    aria-label="Why it is rejected"
                                    aria-invalid={Boolean(errors.note)}
                                    className="h-8 w-40"
                                    required
                                />
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Reject
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    type="button"
                                    onClick={() => setRejecting(false)}
                                >
                                    Cancel
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </TableCell>
        </TableRow>
    );
}

function ReplenishPanel({
    fund,
    banks,
    today,
}: {
    fund: FundSummary;
    banks: Option[];
    today: string;
}) {
    const money = useMoney(fund.currency);
    const shortfall = fund.floatMinor - fund.heldMinor;

    return (
        <Panel className="mb-3">
            <PanelHeader
                title="Top up to the float"
                description="Raises a transfer payment request for what the fund is short of its float. It is approved and paid out like any payment. Every voucher must be checked first."
            />
            <PanelBody>
                {fund.replenishment ? (
                    <p className="text-sm">
                        {fund.replenishment.number} for{' '}
                        {money(fund.replenishment.amountMinor)} is on its way.
                    </p>
                ) : shortfall <= 0 ? (
                    <p className="text-sm text-muted-foreground">
                        The fund holds its float.
                    </p>
                ) : (
                    <Form
                        {...replenish.form(fund.id)}
                        className="grid gap-3 sm:grid-cols-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="replenish-from"
                                    label="From"
                                    error={errors.account_id ?? errors.fund}
                                >
                                    <Select
                                        id="replenish-from"
                                        name="account_id"
                                        required
                                    >
                                        {banks.map((bank) => (
                                            <option
                                                key={bank.id}
                                                value={bank.id}
                                            >
                                                {bank.label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field
                                    id="replenish-on"
                                    label="Pay on"
                                    error={errors.pay_on}
                                >
                                    <Input
                                        id="replenish-on"
                                        name="pay_on"
                                        type="date"
                                        defaultValue={today}
                                        required
                                    />
                                </Field>
                                <div className="flex items-end">
                                    <Button type="submit" disabled={processing}>
                                        Ask for {money(shortfall)}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </PanelBody>
        </Panel>
    );
}

function CountPanel({ fund }: { fund: FundSummary }) {
    return (
        <Panel className="mb-3">
            <PanelHeader
                title="Count the cash"
                description="Count what is in the box. A difference from the books is posted to Cash over and short, with your explanation."
            />
            <PanelBody>
                <Form
                    {...storeCount.form(fund.id)}
                    resetOnSuccess
                    className="grid gap-3 sm:grid-cols-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                id="counted"
                                label={`Counted${fund.currency ? ` (${fund.currency})` : ''}`}
                                error={errors.counted}
                            >
                                <Input
                                    id="counted"
                                    name="counted"
                                    inputMode="decimal"
                                    className="text-right tabular-nums"
                                    required
                                />
                            </Field>
                            <Field
                                id="count-note"
                                label="Explanation"
                                error={errors.note}
                            >
                                <Input id="count-note" name="note" />
                            </Field>
                            <div className="flex items-end">
                                <Button type="submit" disabled={processing}>
                                    Record count
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

function CountsPanel({
    counts,
    currency,
}: {
    counts: Props['counts'];
    currency: string | null;
}) {
    return (
        <Panel className="mb-3">
            <PanelHeader title="Counts" />
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Counted on</TableHead>
                        <TableHead>By</TableHead>
                        <TableHead className="text-right">Books</TableHead>
                        <TableHead className="text-right">Counted</TableHead>
                        <TableHead className="text-right">Difference</TableHead>
                        <TableHead>Explanation</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {counts.length === 0 && (
                        <TableEmpty colSpan={6}>Not counted yet.</TableEmpty>
                    )}
                    {counts.map((count) => (
                        <TableRow key={count.id}>
                            <TableCell className="tabular-nums">
                                {count.countedOn}
                            </TableCell>
                            <TableCell>{count.by}</TableCell>
                            <TableCell className="text-right">
                                <Amount
                                    minor={count.bookMinor}
                                    currency={currency}
                                />
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount
                                    minor={count.countedMinor}
                                    currency={currency}
                                />
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount
                                    minor={count.differenceMinor}
                                    currency={currency}
                                />
                            </TableCell>
                            <TableCell className="text-xs text-muted-foreground">
                                {count.note}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </Panel>
    );
}

function ManagePanel({ fund }: { fund: FundSummary }) {
    const money = useMoney(fund.currency);

    return (
        <Panel>
            <PanelHeader
                title="Manage the fund"
                description="Changes are recorded in the audit trail."
            />
            <PanelBody className="grid gap-6">
                <Form
                    {...update.form(fund.id)}
                    className="grid gap-3 sm:grid-cols-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                id="float"
                                label="Float"
                                error={errors.float}
                            >
                                <Input
                                    id="float"
                                    name="float"
                                    inputMode="decimal"
                                    defaultValue={fund.floatMajor}
                                    className="text-right tabular-nums"
                                    required
                                />
                            </Field>
                            <Field
                                id="replenish_at"
                                label="Top up at"
                                error={errors.replenish_at}
                            >
                                <Input
                                    id="replenish_at"
                                    name="replenish_at"
                                    inputMode="decimal"
                                    defaultValue={fund.replenishAtMajor}
                                    className="text-right tabular-nums"
                                />
                            </Field>
                            <Field
                                id="status"
                                label="In use"
                                error={errors.status}
                            >
                                <Select
                                    id="status"
                                    name="status"
                                    defaultValue={fund.status}
                                >
                                    <option value="active">Active</option>
                                    <option value="suspended">
                                        Suspended (no vouchers)
                                    </option>
                                </Select>
                            </Field>
                            <div className="flex items-end">
                                <Button type="submit" disabled={processing}>
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <Form
                    {...custodian.form(fund.id)}
                    className="grid gap-3 sm:grid-cols-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                id="new-custodian"
                                label="Hand over to (email)"
                                error={errors.custodian}
                                hint="Count the cash first."
                                className="sm:col-span-3"
                            >
                                <Input
                                    id="new-custodian"
                                    name="custodian"
                                    type="email"
                                    required
                                />
                            </Field>
                            <div className="flex items-end">
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Hand over
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <Form {...close.form(fund.id)} className="grid gap-2">
                    {({ errors, processing }) => (
                        <>
                            <p className="text-sm text-muted-foreground">
                                To close the fund, return its{' '}
                                {money(fund.heldMinor)} to a bank with a
                                transfer payment request, and check every
                                voucher.
                            </p>
                            {errors.fund && (
                                <p className="text-sm text-destructive">
                                    {errors.fund}
                                </p>
                            )}
                            <div>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Close fund
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}
