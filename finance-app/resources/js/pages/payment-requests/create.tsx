import { Form, Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { Field, Select } from '@/components/field';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
import { create, store } from '@/routes/payment-requests';

type Bill = {
    id: string;
    number: string;
    billDate: string;
    outstandingMinor: number;
    currency: string | null;
    currencyOutstandingMinor: number | null;
};

type Option = { id: string; label: string; currency?: string | null };

type Props = {
    kind: string;
    suppliers: { id: string; name: string }[];
    supplierId: string | null;
    bills: Bill[];
    banks: Option[];
    accounts: Option[];
    dimensions: { costCentres: Option[]; projects: Option[] };
    today: string;
};

const KINDS = [
    { value: 'expense', label: 'Expense' },
    { value: 'supplier', label: 'Supplier payment' },
    { value: 'advance', label: 'Employee advance' },
    { value: 'transfer', label: 'Transfer between accounts' },
];

/**
 * Asks for money to be paid: it goes through review and approval, and reaches
 * the books when it is disbursed.
 */
export default function NewPaymentRequest({
    kind: initialKind,
    suppliers,
    supplierId,
    bills,
    banks,
    accounts,
    dimensions,
    today,
}: Props) {
    const { currency: books } = usePage().props;
    const [kind, setKind] = useState(initialKind);
    const [fromId, setFromId] = useState('');
    const [toId, setToId] = useState('');
    const from = banks.find((bank) => bank.id === fromId);
    const to = banks.find((bank) => bank.id === toId);
    const billCurrency = bills.find((bill) => bill.currency)?.currency ?? null;
    const currency =
        from?.currency ?? (kind === 'supplier' ? billCurrency : null);

    return (
        <>
            <Head title="New payment request" />
            <PageHeader
                title="New payment request"
                description="Nothing is paid or posted until it is approved and disbursed."
            />

            <Panel>
                <PanelBody>
                    <Field
                        id="kind-choice"
                        label="What is it for"
                        className="mb-3 max-w-md"
                    >
                        <Select
                            id="kind-choice"
                            value={kind}
                            onChange={(event) => setKind(event.target.value)}
                        >
                            {KINDS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    {kind === 'supplier' && (
                        <Field
                            id="supplier"
                            label="Supplier"
                            className="mb-3 max-w-md"
                        >
                            <Select
                                id="supplier"
                                value={supplierId ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        create.url({
                                            query: {
                                                kind: 'supplier',
                                                supplier:
                                                    event.target.value ||
                                                    undefined,
                                            },
                                        }),
                                        {},
                                        { preserveState: false },
                                    )
                                }
                            >
                                <option value="">Choose a supplier…</option>
                                {suppliers.map((supplier) => (
                                    <option
                                        key={supplier.id}
                                        value={supplier.id}
                                    >
                                        {supplier.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    )}

                    {(kind !== 'supplier' || supplierId) && (
                        <Form {...store.form()} className="grid gap-3">
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="kind"
                                        value={kind}
                                    />
                                    {kind === 'supplier' && supplierId && (
                                        <input
                                            type="hidden"
                                            name="supplier_id"
                                            value={supplierId}
                                        />
                                    )}
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <Field
                                            id="account_id"
                                            label="Pay from"
                                            error={errors.account_id}
                                        >
                                            <Select
                                                id="account_id"
                                                name="account_id"
                                                required
                                                value={fromId}
                                                onChange={(event) =>
                                                    setFromId(
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">
                                                    Choose an account…
                                                </option>
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
                                            id="amount"
                                            label={`Amount (${currency ?? books})`}
                                            error={errors.amount}
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
                                            id="pay_on"
                                            label="Pay on"
                                            error={errors.pay_on}
                                        >
                                            <Input
                                                id="pay_on"
                                                name="pay_on"
                                                type="date"
                                                defaultValue={today}
                                                required
                                            />
                                        </Field>

                                        {kind === 'expense' && (
                                            <Field
                                                id="other_account_id"
                                                label="Expense account"
                                                error={errors.other_account_id}
                                            >
                                                <Select
                                                    id="other_account_id"
                                                    name="other_account_id"
                                                    required
                                                    defaultValue=""
                                                >
                                                    <option value="">
                                                        Choose an account…
                                                    </option>
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
                                        )}
                                        {(kind === 'expense' ||
                                            kind === 'advance') && (
                                            <>
                                                <Field
                                                    id="payee"
                                                    label={
                                                        kind === 'advance'
                                                            ? 'Employee'
                                                            : 'Paid to'
                                                    }
                                                    error={errors.payee}
                                                >
                                                    <Input
                                                        id="payee"
                                                        name="payee"
                                                        maxLength={255}
                                                        required={
                                                            kind === 'advance'
                                                        }
                                                    />
                                                </Field>
                                                <Field
                                                    id="cost_centre_id"
                                                    label="Cost centre"
                                                    error={
                                                        errors.cost_centre_id
                                                    }
                                                >
                                                    <Select
                                                        id="cost_centre_id"
                                                        name="cost_centre_id"
                                                        defaultValue=""
                                                    >
                                                        <option value="">
                                                            None
                                                        </option>
                                                        {dimensions.costCentres.map(
                                                            (value) => (
                                                                <option
                                                                    key={
                                                                        value.id
                                                                    }
                                                                    value={
                                                                        value.id
                                                                    }
                                                                >
                                                                    {
                                                                        value.label
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </Select>
                                                </Field>
                                            </>
                                        )}
                                        {kind === 'transfer' && (
                                            <>
                                                <Field
                                                    id="to_account_id"
                                                    label="To"
                                                    error={errors.to_account_id}
                                                >
                                                    <Select
                                                        id="to_account_id"
                                                        name="to_account_id"
                                                        required
                                                        value={toId}
                                                        onChange={(event) =>
                                                            setToId(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    >
                                                        <option value="">
                                                            Choose an account…
                                                        </option>
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
                                                {(from?.currency ?? null) !==
                                                    (to?.currency ?? null) && (
                                                    <Field
                                                        id="received"
                                                        label={`Arrives as (${to?.currency ?? books})`}
                                                        error={errors.received}
                                                    >
                                                        <Input
                                                            id="received"
                                                            name="received"
                                                            inputMode="decimal"
                                                            className="text-right tabular-nums"
                                                        />
                                                    </Field>
                                                )}
                                            </>
                                        )}
                                        {currency && kind !== 'transfer' && (
                                            <Field
                                                id="rate"
                                                label="Rate"
                                                error={errors.rate}
                                                hint={`Blank for the ${currency} rate on the date.`}
                                            >
                                                <Input
                                                    id="rate"
                                                    name="rate"
                                                    inputMode="decimal"
                                                />
                                            </Field>
                                        )}
                                        {kind === 'supplier' && (
                                            <Field
                                                id="reference"
                                                label="Reference"
                                                error={errors.reference}
                                            >
                                                <Input
                                                    id="reference"
                                                    name="reference"
                                                    maxLength={64}
                                                />
                                            </Field>
                                        )}
                                        <Field
                                            id="description"
                                            label="What it is for"
                                            error={errors.description}
                                            className="sm:col-span-3"
                                        >
                                            <Input
                                                id="description"
                                                name="description"
                                                maxLength={200}
                                                required={kind !== 'supplier'}
                                            />
                                        </Field>
                                    </div>

                                    {kind === 'supplier' && (
                                        <Panel>
                                            <PanelHeader title="Set against bills" />
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>
                                                            Bill
                                                        </TableHead>
                                                        <TableHead>
                                                            Date
                                                        </TableHead>
                                                        <TableHead className="text-right">
                                                            Outstanding
                                                        </TableHead>
                                                        <TableHead className="w-40 text-right">
                                                            Pay
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {bills.length === 0 && (
                                                        <TableEmpty colSpan={4}>
                                                            No unpaid bills.
                                                        </TableEmpty>
                                                    )}
                                                    {bills.map((bill) => (
                                                        <TableRow key={bill.id}>
                                                            <TableCell className="font-mono text-xs">
                                                                {bill.number}
                                                            </TableCell>
                                                            <TableCell className="tabular-nums">
                                                                {bill.billDate}
                                                            </TableCell>
                                                            <TableCell className="text-right">
                                                                <Amount
                                                                    minor={
                                                                        bill.currency
                                                                            ? (bill.currencyOutstandingMinor ??
                                                                              0)
                                                                            : bill.outstandingMinor
                                                                    }
                                                                    currency={
                                                                        bill.currency
                                                                    }
                                                                />
                                                            </TableCell>
                                                            <TableCell>
                                                                <Input
                                                                    name={`allocations[${bill.id}]`}
                                                                    inputMode="decimal"
                                                                    className="text-right tabular-nums"
                                                                    aria-label={`Pay against ${bill.number}`}
                                                                />
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </Panel>
                                    )}
                                    {errors.allocations && (
                                        <p className="text-sm text-destructive">
                                            {errors.allocations}
                                        </p>
                                    )}

                                    <div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Submit request
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
