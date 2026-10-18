import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowLeftRight,
    ArrowUpFromLine,
    Landmark,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Field, Select } from '@/components/field';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
import { useCan } from '@/lib/access';
import { sourceLabel, useMoney } from '@/lib/format';
import { show as showAccount, store as storeAccount } from '@/routes/accounts';
import { moneyIn, moneyOut, transfer } from '@/routes/banking';
import { show as showJournal } from '@/routes/journals';

type Option = { id: string; label: string; type?: string };

type Props = {
    banks: {
        id: string;
        code: string;
        name: string;
        kind: string | null;
        kindLabel: string | null;
        bankName: string | null;
        accountNumber: string | null;
        active: boolean;
        balanceMinor: number;
    }[];
    movements: {
        id: string;
        journalId: string;
        number: string;
        date: string;
        memo: string;
        source: string;
        account: string | null;
        inMinor: number;
        outMinor: number;
    }[];
    accounts: Option[];
    bankOptions: Option[];
    bankKinds: { value: string; label: string }[];
    today: string;
};

type Action = 'out' | 'in' | 'transfer' | 'account' | null;

function Options({
    options,
    placeholder,
}: {
    options: Option[];
    placeholder: string;
}) {
    return (
        <>
            <option value="">{placeholder}</option>
            {options.map((option) => (
                <option key={option.id} value={option.id}>
                    {option.label}
                </option>
            ))}
        </>
    );
}

function AmountDateDescription({
    errors,
    today,
}: {
    errors: Record<string, string>;
    today: string;
}) {
    return (
        <>
            <Field id="amount" label="Amount" error={errors.amount}>
                <Input
                    id="amount"
                    name="amount"
                    inputMode="decimal"
                    className="text-right tabular-nums"
                    required
                />
            </Field>
            <Field id="entry_date" label="Date" error={errors.entry_date}>
                <Input
                    id="entry_date"
                    name="entry_date"
                    type="date"
                    defaultValue={today}
                    required
                />
            </Field>
            <Field
                id="description"
                label="Description"
                error={errors.description}
                className="sm:col-span-2"
            >
                <Input
                    id="description"
                    name="description"
                    required
                    maxLength={255}
                />
            </Field>
        </>
    );
}

/**
 * Bank and cash accounts: balances, recent movements, and money in and out.
 */
export default function Banking({
    banks,
    movements,
    accounts,
    bankOptions,
    bankKinds,
    today,
}: Props) {
    const can = useCan();
    const money = useMoney();
    const [action, setAction] = useState<Action>(null);
    const total = banks.reduce((sum, bank) => sum + bank.balanceMinor, 0);
    const income = accounts.filter((account) => account.type !== 'expense');
    const spending = accounts.filter((account) => account.type !== 'revenue');
    const toggle = (next: Action) =>
        setAction((current) => (current === next ? null : next));

    return (
        <>
            <Head title="Bank & cash" />
            <PageHeader
                title="Bank & cash"
                description={`Across all accounts: ${money(total)}.`}
                actions={
                    <>
                        {can('finance.banking.manage') && (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => toggle('in')}
                                >
                                    <ArrowDownToLine
                                        className="size-4"
                                        aria-hidden
                                    />
                                    Money in
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => toggle('out')}
                                >
                                    <ArrowUpFromLine
                                        className="size-4"
                                        aria-hidden
                                    />
                                    Money out
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => toggle('transfer')}
                                >
                                    <ArrowLeftRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                    Transfer
                                </Button>
                            </>
                        )}
                        {can('finance.accounts.manage') && (
                            <Button onClick={() => toggle('account')}>
                                <Plus className="size-4" aria-hidden />
                                New account
                            </Button>
                        )}
                    </>
                }
            />

            {(action === 'out' || action === 'in') && (
                <Panel className="mb-3">
                    <PanelHeader
                        title={action === 'out' ? 'Money out' : 'Money in'}
                        description={
                            action === 'out'
                                ? 'Spending not on a supplier bill: bank charges, small expenses, owner drawings.'
                                : 'Money received outside invoices: other income, loans, owner capital.'
                        }
                    />
                    <PanelBody>
                        <Form
                            {...(action === 'out'
                                ? moneyOut.form()
                                : moneyIn.form())}
                            onSuccess={() => setAction(null)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="account_id"
                                        label={
                                            action === 'out'
                                                ? 'Paid from'
                                                : 'Received into'
                                        }
                                        error={errors.account_id}
                                    >
                                        <Select
                                            id="account_id"
                                            name="account_id"
                                            required
                                            defaultValue=""
                                        >
                                            <Options
                                                options={bankOptions}
                                                placeholder="Choose an account…"
                                            />
                                        </Select>
                                    </Field>
                                    <Field
                                        id="other_account_id"
                                        label={
                                            action === 'out'
                                                ? 'Spent on'
                                                : 'Income from'
                                        }
                                        error={errors.other_account_id}
                                    >
                                        <Select
                                            id="other_account_id"
                                            name="other_account_id"
                                            required
                                            defaultValue=""
                                        >
                                            <Options
                                                options={
                                                    action === 'out'
                                                        ? spending
                                                        : income
                                                }
                                                placeholder="Choose an account…"
                                            />
                                        </Select>
                                    </Field>
                                    <AmountDateDescription
                                        errors={errors}
                                        today={today}
                                    />
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Record
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {action === 'transfer' && (
                <Panel className="mb-3">
                    <PanelHeader
                        title="Transfer between accounts"
                        description="For example, banking the day's cash."
                    />
                    <PanelBody>
                        <Form
                            {...transfer.form()}
                            onSuccess={() => setAction(null)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="account_id"
                                        label="From"
                                        error={errors.account_id}
                                    >
                                        <Select
                                            id="account_id"
                                            name="account_id"
                                            required
                                            defaultValue=""
                                        >
                                            <Options
                                                options={bankOptions}
                                                placeholder="Choose an account…"
                                            />
                                        </Select>
                                    </Field>
                                    <Field
                                        id="to_account_id"
                                        label="To"
                                        error={errors.to_account_id}
                                    >
                                        <Select
                                            id="to_account_id"
                                            name="to_account_id"
                                            required
                                            defaultValue=""
                                        >
                                            <Options
                                                options={bankOptions}
                                                placeholder="Choose an account…"
                                            />
                                        </Select>
                                    </Field>
                                    <AmountDateDescription
                                        errors={errors}
                                        today={today}
                                    />
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Transfer
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {action === 'account' && (
                <Panel className="mb-3">
                    <PanelHeader title="New bank or cash account" />
                    <PanelBody>
                        <Form
                            {...storeAccount.form()}
                            onSuccess={() => setAction(null)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="type"
                                        value="asset"
                                    />
                                    <Field
                                        id="code"
                                        label="Code"
                                        error={errors.code}
                                        hint="For example 1011"
                                    >
                                        <Input
                                            id="code"
                                            name="code"
                                            required
                                            maxLength={16}
                                        />
                                    </Field>
                                    <Field
                                        id="name"
                                        label="Name"
                                        error={errors.name}
                                    >
                                        <Input id="name" name="name" required />
                                    </Field>
                                    <Field
                                        id="bank_kind"
                                        label="Kind"
                                        error={errors.bank_kind}
                                    >
                                        <Select
                                            id="bank_kind"
                                            name="bank_kind"
                                            defaultValue="bank"
                                        >
                                            {bankKinds.map((kind) => (
                                                <option
                                                    key={kind.value}
                                                    value={kind.value}
                                                >
                                                    {kind.label}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field
                                        id="bank_name"
                                        label="Bank or provider"
                                        error={errors.bank_name}
                                    >
                                        <Input
                                            id="bank_name"
                                            name="bank_name"
                                        />
                                    </Field>
                                    <Field
                                        id="bank_account_number"
                                        label="Account number"
                                        error={errors.bank_account_number}
                                    >
                                        <Input
                                            id="bank_account_number"
                                            name="bank_account_number"
                                        />
                                    </Field>
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add account
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {banks.length === 0 ? (
                <Panel className="mb-4">
                    <EmptyState
                        icon={Landmark}
                        title="No bank or cash accounts"
                    />
                </Panel>
            ) : (
                <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {banks.map((bank) => (
                        <Link
                            key={bank.id}
                            href={showAccount(bank.id)}
                            className="rounded-lg border bg-card p-4 transition-colors hover:border-primary/40"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="font-medium">{bank.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {[bank.bankName, bank.accountNumber]
                                            .filter(Boolean)
                                            .join(' · ') || bank.code}
                                    </p>
                                </div>
                                <StatusBadge tone="info">
                                    {bank.kindLabel}
                                </StatusBadge>
                            </div>
                            <p className="mt-3 text-xl font-semibold">
                                <Amount minor={bank.balanceMinor} />
                            </p>
                        </Link>
                    ))}
                </div>
            )}

            <Panel>
                <PanelHeader title="Recent movements" />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>Journal</TableHead>
                            <TableHead>Memo</TableHead>
                            <TableHead>Account</TableHead>
                            <TableHead>From</TableHead>
                            <TableHead className="text-right">In</TableHead>
                            <TableHead className="text-right">Out</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {movements.length === 0 && (
                            <TableEmpty colSpan={7}>
                                No money has moved yet.
                            </TableEmpty>
                        )}
                        {movements.map((movement) => (
                            <TableRow key={movement.id}>
                                <TableCell className="tabular-nums">
                                    {movement.date}
                                </TableCell>
                                <TableCell className="font-mono text-xs">
                                    <Link
                                        href={showJournal(movement.journalId)}
                                        className="text-primary hover:underline"
                                    >
                                        {movement.number}
                                    </Link>
                                </TableCell>
                                <TableCell className="max-w-80 truncate">
                                    {movement.memo}
                                </TableCell>
                                <TableCell>{movement.account}</TableCell>
                                <TableCell>
                                    {sourceLabel(movement.source)}
                                </TableCell>
                                <TableCell className="text-right text-emerald-700">
                                    <Amount
                                        minor={movement.inMinor}
                                        blankZero
                                    />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount
                                        minor={movement.outMinor}
                                        blankZero
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
