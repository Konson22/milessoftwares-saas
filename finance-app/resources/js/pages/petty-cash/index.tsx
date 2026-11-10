import { Form, Head, Link } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { Amount } from '@/components/amount';
import { Field, Select } from '@/components/field';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
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
import { show, store } from '@/routes/petty-cash';

export type FundSummary = {
    id: string;
    name: string;
    account: string;
    currency: string | null;
    custodian: string | null;
    custodianEmail: string | null;
    movedIn: boolean;
    floatMinor: number;
    floatMajor: string;
    replenishAtMajor: string;
    replenishAtMinor: number | null;
    heldMinor: number;
    status: 'active' | 'suspended' | 'closed';
    due: boolean;
    unchecked: number;
    replenishment: {
        number: string;
        status: string;
        amountMinor: number;
    } | null;
};

type Props = {
    funds: FundSummary[];
    accounts: { id: string; label: string; currency: string | null }[];
    canManage: boolean;
    limit: string | null;
};

export const FUND_STATUS = {
    active: { label: 'Active', tone: 'success' },
    suspended: { label: 'Suspended', tone: 'warning' },
    closed: { label: 'Closed', tone: 'neutral' },
} as const;

/**
 * Petty cash funds: each a petty cash account of the chart kept at a fixed
 * float by its custodian.
 */
export default function PettyCash({
    funds,
    accounts,
    canManage,
    limit,
}: Props) {
    return (
        <>
            <Head title="Petty cash" />
            <PageHeader
                title="Petty cash"
                description={
                    limit === null
                        ? 'No voucher can be recorded until the petty cash limit is set in Approval rules.'
                        : `Each fund is kept at its float. The most one voucher may be is ${limit}.`
                }
            />

            <Panel className="mb-3">
                {funds.length === 0 ? (
                    <EmptyState icon={Wallet} title="No petty cash funds">
                        {canManage
                            ? 'Set one up below, on a petty cash account of the chart.'
                            : 'A manager sets up funds.'}
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Fund</TableHead>
                                <TableHead>Custodian</TableHead>
                                <TableHead className="text-right">
                                    Float
                                </TableHead>
                                <TableHead className="text-right">
                                    Holds
                                </TableHead>
                                <TableHead>To do</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {funds.map((fund) => (
                                <TableRow key={fund.id}>
                                    <TableCell>
                                        <Link
                                            href={show(fund.id)}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            {fund.name}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {fund.account}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {fund.custodian ?? (
                                            <span className="text-muted-foreground">
                                                No custodian yet
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={fund.floatMinor}
                                            currency={fund.currency}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={fund.heldMinor}
                                            currency={fund.currency}
                                        />
                                    </TableCell>
                                    <TableCell className="text-xs">
                                        <ToDo fund={fund} />
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={FUND_STATUS[fund.status].tone}
                                        >
                                            {FUND_STATUS[fund.status].label}
                                        </StatusBadge>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            {canManage && <NewFund accounts={accounts} />}
        </>
    );
}

export function ToDo({ fund }: { fund: FundSummary }) {
    const items = [
        fund.unchecked > 0 &&
            `${fund.unchecked} voucher${fund.unchecked === 1 ? '' : 's'} to check`,
        fund.replenishment && `${fund.replenishment.number} on its way`,
        !fund.replenishment && fund.due && 'Due for a top-up',
    ].filter(Boolean);

    return items.length === 0 ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        <span>{items.join(' · ')}</span>
    );
}

function NewFund({ accounts }: { accounts: Props['accounts'] }) {
    return (
        <Panel>
            <PanelHeader
                title="Set up a fund"
                description="On a petty cash account of the chart. It holds nothing until its float is asked for and paid."
            />
            <PanelBody>
                {accounts.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Every petty cash account already has a fund. Add a petty
                        cash account to the chart first.
                    </p>
                ) : (
                    <Form
                        {...store.form()}
                        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="name"
                                    label="Name"
                                    error={errors.name}
                                >
                                    <Input id="name" name="name" required />
                                </Field>
                                <Field
                                    id="account_id"
                                    label="Petty cash account"
                                    error={errors.account_id}
                                >
                                    <Select
                                        id="account_id"
                                        name="account_id"
                                        required
                                    >
                                        {accounts.map((account) => (
                                            <option
                                                key={account.id}
                                                value={account.id}
                                            >
                                                {account.label}
                                                {account.currency
                                                    ? ` (${account.currency})`
                                                    : ''}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field
                                    id="custodian"
                                    label="Custodian's email"
                                    error={errors.custodian}
                                    hint="A Cashier or Finance Officer who has signed in to Finance."
                                >
                                    <Input
                                        id="custodian"
                                        name="custodian"
                                        type="email"
                                        required
                                    />
                                </Field>
                                <Field
                                    id="float"
                                    label="Float"
                                    error={errors.float}
                                    hint="The amount the fund is kept at."
                                >
                                    <Input
                                        id="float"
                                        name="float"
                                        inputMode="decimal"
                                        className="text-right tabular-nums"
                                        required
                                    />
                                </Field>
                                <Field
                                    id="replenish_at"
                                    label="Top up at"
                                    error={errors.replenish_at}
                                    hint="Shown as due at or below this. Optional."
                                >
                                    <Input
                                        id="replenish_at"
                                        name="replenish_at"
                                        inputMode="decimal"
                                        className="text-right tabular-nums"
                                    />
                                </Field>
                                <div className="flex items-end">
                                    <Button type="submit" disabled={processing}>
                                        Set up fund
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
