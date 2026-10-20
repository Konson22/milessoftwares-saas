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
import { useAmountStep } from '@/lib/format';
import { create, store } from '@/routes/supplier-payments';

type Bill = {
    id: string;
    number: string;
    billDate: string;
    dueOn: string | null;
    outstandingMinor: number;
    currency: string | null;
    currencyOutstandingMinor: number | null;
};

type Props = {
    suppliers: { id: string; name: string }[];
    supplierId: string | null;
    banks: { id: string; label: string; currency: string | null }[];
    bills: Bill[];
    today: string;
};

/** What is left on a bill, in its own currency. */
function owed(bill: Bill): number {
    return bill.currency === null
        ? bill.outstandingMinor
        : (bill.currencyOutstandingMinor ?? 0);
}

/**
 * Paying a supplier: from which account, how much, against which bills.
 * A payment is in one currency: bills in another currency are paid from
 * an account in it, or from the books' currency at the day's rate.
 */
export default function PaySupplier({
    suppliers,
    supplierId,
    banks,
    bills,
    today,
}: Props) {
    const { currency: books } = usePage().props;
    const groups = [...new Set(bills.map((bill) => bill.currency ?? ''))];
    const [group, setGroup] = useState(groups[0] ?? '');
    const shown = bills.filter((bill) => (bill.currency ?? '') === group);
    const payable = banks.filter(
        (bank) =>
            bank.currency === null || (group !== '' && bank.currency === group),
    );
    const step = useAmountStep(group || null);
    const scale = step === '1' ? 1 : 100;
    const major = (minor: number) =>
        (minor / scale).toFixed(step === '1' ? 0 : 2);
    const [allocations, setAllocations] = useState<Record<string, string>>({});
    const [amount, setAmount] = useState('');
    const allocated = Object.values(allocations).reduce(
        (sum, value) => sum + Math.round((Number(value) || 0) * scale),
        0,
    );

    const payAll = () => {
        const all = Object.fromEntries(
            shown.map((bill) => [bill.id, major(owed(bill))]),
        );
        setAllocations(all);
        setAmount(major(shown.reduce((sum, bill) => sum + owed(bill), 0)));
    };

    return (
        <>
            <Head title="Pay a supplier" />
            <PageHeader
                title="Pay a supplier"
                description="Records money paid out and sets it against the supplier's bills."
            />

            <Panel>
                <PanelBody>
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
                                            supplier:
                                                event.target.value || undefined,
                                        },
                                    }),
                                    {},
                                    { preserveState: false },
                                )
                            }
                        >
                            <option value="">Choose a supplier…</option>
                            {suppliers.map((supplier) => (
                                <option key={supplier.id} value={supplier.id}>
                                    {supplier.name}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    {supplierId && (
                        <Form {...store.form()} className="grid gap-3">
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="supplier_id"
                                        value={supplierId}
                                    />
                                    <div className="grid gap-3 sm:grid-cols-4">
                                        {groups.length > 1 && (
                                            <Field
                                                id="group"
                                                label="Pay bills in"
                                                className="sm:col-span-4 sm:max-w-48"
                                            >
                                                <Select
                                                    id="group"
                                                    value={group}
                                                    onChange={(event) => {
                                                        setGroup(
                                                            event.target.value,
                                                        );
                                                        setAllocations({});
                                                        setAmount('');
                                                    }}
                                                >
                                                    {groups.map((code) => (
                                                        <option
                                                            key={code}
                                                            value={code}
                                                        >
                                                            {code || books}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </Field>
                                        )}
                                        <Field
                                            id="account_id"
                                            label="Paid from"
                                            error={errors.account_id}
                                        >
                                            <Select
                                                id="account_id"
                                                name="account_id"
                                                required
                                                defaultValue=""
                                            >
                                                <option value="">
                                                    Choose an account…
                                                </option>
                                                {payable.map((bank) => (
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
                                            label={
                                                group
                                                    ? `Amount (${group})`
                                                    : 'Amount'
                                            }
                                            error={errors.amount}
                                        >
                                            <Input
                                                id="amount"
                                                name="amount"
                                                inputMode="decimal"
                                                value={amount}
                                                onChange={(event) =>
                                                    setAmount(
                                                        event.target.value,
                                                    )
                                                }
                                                className="text-right tabular-nums"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            id="paid_on"
                                            label="Date"
                                            error={errors.paid_on}
                                        >
                                            <Input
                                                id="paid_on"
                                                name="paid_on"
                                                type="date"
                                                defaultValue={today}
                                                required
                                            />
                                        </Field>
                                        <Field
                                            id="reference"
                                            label="Reference"
                                            error={errors.reference}
                                            hint="Cheque, transfer or mobile money reference"
                                        >
                                            <Input
                                                id="reference"
                                                name="reference"
                                                maxLength={64}
                                            />
                                        </Field>
                                        {group && (
                                            <Field
                                                id="rate"
                                                label="Rate"
                                                error={errors.rate}
                                                hint={`Blank for the ${group} rate on the date.`}
                                            >
                                                <Input
                                                    id="rate"
                                                    name="rate"
                                                    inputMode="decimal"
                                                />
                                            </Field>
                                        )}
                                        <Field
                                            id="memo"
                                            label="Memo"
                                            error={errors.memo}
                                            className="sm:col-span-4"
                                        >
                                            <Input
                                                id="memo"
                                                name="memo"
                                                maxLength={255}
                                            />
                                        </Field>
                                    </div>

                                    <Panel>
                                        <PanelHeader
                                            title="Set against bills"
                                            description="Anything not set against a bill stays on the supplier's account."
                                            actions={
                                                shown.length > 0 && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={payAll}
                                                    >
                                                        Pay all
                                                    </Button>
                                                )
                                            }
                                        />
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Bill</TableHead>
                                                    <TableHead>Date</TableHead>
                                                    <TableHead>Due</TableHead>
                                                    <TableHead className="text-right">
                                                        Outstanding
                                                    </TableHead>
                                                    <TableHead className="w-40 text-right">
                                                        Pay
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {shown.length === 0 && (
                                                    <TableEmpty colSpan={5}>
                                                        No unpaid bills.
                                                    </TableEmpty>
                                                )}
                                                {shown.map((bill) => (
                                                    <TableRow key={bill.id}>
                                                        <TableCell className="font-mono text-xs">
                                                            {bill.number}
                                                        </TableCell>
                                                        <TableCell className="tabular-nums">
                                                            {bill.billDate}
                                                        </TableCell>
                                                        <TableCell className="tabular-nums">
                                                            {bill.dueOn ?? '—'}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            <Amount
                                                                minor={owed(
                                                                    bill,
                                                                )}
                                                                currency={
                                                                    bill.currency
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell>
                                                            <Input
                                                                name={`allocations[${bill.id}]`}
                                                                inputMode="decimal"
                                                                value={
                                                                    allocations[
                                                                        bill.id
                                                                    ] ?? ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setAllocations(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [bill.id]:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        }),
                                                                    )
                                                                }
                                                                className="text-right tabular-nums"
                                                                aria-label={`Pay against ${bill.number}`}
                                                            />
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                        <p className="border-t px-4 py-2 text-right text-sm">
                                            Set against bills:{' '}
                                            <Amount
                                                minor={allocated}
                                                currency={group || null}
                                                className="font-semibold"
                                            />
                                        </p>
                                    </Panel>
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
                                            Record payment
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
