import { Form, Head, Link } from '@inertiajs/react';
import { ListTree, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Amount } from '@/components/amount';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Field, Select } from '@/components/field';
import { PageHeader } from '@miles/ui/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
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
import { show, store, update } from '@/routes/accounts';

type Account = {
    id: string;
    code: string;
    name: string;
    type: string;
    bankKind: string | null;
    bankName: string | null;
    bankAccountNumber: string | null;
    description: string | null;
    system: boolean;
    active: boolean;
    balanceMinor: number;
};

type Option = { value: string; label: string };

type Props = { accounts: Account[]; types: Option[]; bankKinds: Option[] };

const TYPE_TONES: Record<
    string,
    'info' | 'warning' | 'success' | 'danger' | 'neutral'
> = {
    asset: 'info',
    liability: 'warning',
    equity: 'neutral',
    revenue: 'success',
    expense: 'danger',
};

function AccountFields({
    account,
    types,
    bankKinds,
    errors,
}: {
    account?: Account;
    types: Option[];
    bankKinds: Option[];
    errors: Record<string, string>;
}) {
    const id = account?.id ?? 'new';
    const [type, setType] = useState(account?.type ?? 'expense');
    const [bankKind, setBankKind] = useState(account?.bankKind ?? '');

    return (
        <>
            <Field id={`${id}-code`} label="Code" error={errors.code}>
                <Input
                    id={`${id}-code`}
                    name="code"
                    defaultValue={account?.code}
                    required
                    maxLength={16}
                />
            </Field>
            <Field
                id={`${id}-name`}
                label="Name"
                error={errors.name}
                className="sm:col-span-2"
            >
                <Input
                    id={`${id}-name`}
                    name="name"
                    defaultValue={account?.name}
                    required
                />
            </Field>
            <Field id={`${id}-type`} label="Type" error={errors.type}>
                <Select
                    id={`${id}-type`}
                    name="type"
                    value={type}
                    onChange={(event) => {
                        setType(event.target.value);
                        if (event.target.value !== 'asset') {
                            setBankKind('');
                        }
                    }}
                    disabled={account?.system}
                >
                    {types.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>
                {account?.system && (
                    <input type="hidden" name="type" value={type} />
                )}
            </Field>
            {type === 'asset' && (
                <Field
                    id={`${id}-bank-kind`}
                    label="Holds money"
                    error={errors.bank_kind}
                >
                    <Select
                        id={`${id}-bank-kind`}
                        name="bank_kind"
                        value={bankKind}
                        onChange={(event) => setBankKind(event.target.value)}
                        disabled={account?.system}
                    >
                        <option value="">No</option>
                        {bankKinds.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    {account?.system && (
                        <input
                            type="hidden"
                            name="bank_kind"
                            value={bankKind}
                        />
                    )}
                </Field>
            )}
            {bankKind !== '' && (
                <>
                    <Field
                        id={`${id}-bank-name`}
                        label="Bank or provider"
                        error={errors.bank_name}
                    >
                        <Input
                            id={`${id}-bank-name`}
                            name="bank_name"
                            defaultValue={account?.bankName ?? ''}
                        />
                    </Field>
                    <Field
                        id={`${id}-bank-number`}
                        label="Account number"
                        error={errors.bank_account_number}
                    >
                        <Input
                            id={`${id}-bank-number`}
                            name="bank_account_number"
                            defaultValue={account?.bankAccountNumber ?? ''}
                        />
                    </Field>
                </>
            )}
            <Field
                id={`${id}-description`}
                label="Description"
                error={errors.description}
                className="sm:col-span-2"
            >
                <Input
                    id={`${id}-description`}
                    name="description"
                    defaultValue={account?.description ?? ''}
                />
            </Field>
        </>
    );
}

/**
 * The chart of accounts, by type, with balances.
 */
export default function Accounts({ accounts, types, bankKinds }: Props) {
    const can = useCan();
    const canManage = can('finance.accounts.manage');
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [type, setType] = useState('');

    const shown = useMemo(() => {
        const term = search.trim().toLowerCase();

        return accounts.filter(
            (account) =>
                (type === '' || account.type === type) &&
                (term === '' ||
                    `${account.code} ${account.name}`
                        .toLowerCase()
                        .includes(term)),
        );
    }, [accounts, search, type]);

    return (
        <>
            <Head title="Chart of accounts" />
            <PageHeader
                title="Chart of accounts"
                description="Every account the books use. Accounts marked System are posted to automatically."
                actions={
                    canManage && (
                        <Button onClick={() => setAdding((value) => !value)}>
                            <Plus className="size-4" aria-hidden />
                            New account
                        </Button>
                    )
                }
            />

            {adding && (
                <Panel className="mb-5">
                    <PanelHeader title="New account" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            onSuccess={() => setAdding(false)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <AccountFields
                                        types={types}
                                        bankKinds={bankKinds}
                                        errors={errors}
                                    />
                                    <div className="flex gap-2 sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add account
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setAdding(false)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                <PanelToolbar>
                    <SearchField
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search code or name"
                        aria-label="Search accounts"
                    />
                    <Select
                        value={type}
                        onChange={(event) => setType(event.target.value)}
                        aria-label="Account type"
                        className="w-44"
                    >
                        <option value="">All types</option>
                        {types.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                </PanelToolbar>
                {accounts.length === 0 ? (
                    <EmptyState icon={ListTree} title="No accounts yet" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-24">Code</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Flags</TableHead>
                                <TableHead className="text-right">
                                    Balance
                                </TableHead>
                                {canManage && <TableHead className="w-16" />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shown.length === 0 && (
                                <TableEmpty colSpan={canManage ? 6 : 5}>
                                    No account matches.
                                </TableEmpty>
                            )}
                            {shown.map((account) =>
                                editing === account.id ? (
                                    <TableRow
                                        key={account.id}
                                        className="hover:bg-transparent"
                                    >
                                        <TableCell
                                            colSpan={canManage ? 6 : 5}
                                            className="bg-muted/30 p-4"
                                        >
                                            <Form
                                                {...update.form(account.id)}
                                                onSuccess={() =>
                                                    setEditing(null)
                                                }
                                                className="grid gap-3 sm:grid-cols-4"
                                            >
                                                {({ errors, processing }) => (
                                                    <>
                                                        <AccountFields
                                                            account={account}
                                                            types={types}
                                                            bankKinds={
                                                                bankKinds
                                                            }
                                                            errors={errors}
                                                        />
                                                        {!account.system && (
                                                            <label className="flex items-center gap-2 self-end pb-2 text-sm">
                                                                <input
                                                                    type="hidden"
                                                                    name="active"
                                                                    value="0"
                                                                />
                                                                <input
                                                                    type="checkbox"
                                                                    name="active"
                                                                    value="1"
                                                                    defaultChecked={
                                                                        account.active
                                                                    }
                                                                />
                                                                Open for posting
                                                            </label>
                                                        )}
                                                        {errors.active && (
                                                            <p className="text-sm text-destructive sm:col-span-4">
                                                                {errors.active}
                                                            </p>
                                                        )}
                                                        <div className="flex gap-2 sm:col-span-4">
                                                            <Button
                                                                type="submit"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Save
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setEditing(
                                                                        null,
                                                                    )
                                                                }
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </div>
                                                    </>
                                                )}
                                            </Form>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    <TableRow key={account.id}>
                                        <TableCell className="font-mono text-xs">
                                            {account.code}
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                href={show(account.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {account.name}
                                            </Link>
                                            {account.description && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {account.description}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                tone={TYPE_TONES[account.type]}
                                            >
                                                {
                                                    types.find(
                                                        (option) =>
                                                            option.value ===
                                                            account.type,
                                                    )?.label
                                                }
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell className="space-x-1">
                                            {account.system && (
                                                <StatusBadge>
                                                    System
                                                </StatusBadge>
                                            )}
                                            {account.bankKind && (
                                                <StatusBadge tone="info">
                                                    {
                                                        bankKinds.find(
                                                            (option) =>
                                                                option.value ===
                                                                account.bankKind,
                                                        )?.label
                                                    }
                                                </StatusBadge>
                                            )}
                                            {!account.active && (
                                                <StatusBadge tone="warning">
                                                    Closed
                                                </StatusBadge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount
                                                minor={account.balanceMinor}
                                            />
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setEditing(account.id)
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ),
                            )}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
