import { Form, Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { CurrencySelect } from '@/components/currency-select';
import { Field } from '@/components/field';
import { formatMoney } from '@/lib/money';
import { useMoney } from '@/lib/use-money';
import { petty } from '@/routes/cash';

type Transfer = {
    id: string;
    number: string;
    kind: string;
    status: string;
    currency: string;
    declaredMinor: number;
    countedMinor: number | null;
    source: string;
    destination: string;
    notes: string | null;
};

type MoneyLine = { currency: string; minor: number };

type SafeRow = {
    id: string;
    name: string;
    code: string;
    status: string;
    balanceMinor: number;
    balances?: MoneyLine[];
};

type Props = {
    shift: {
        id: string;
        number: string;
        drawerMinor: number;
        openedBy: string;
        drawers?: MoneyLine[];
    } | null;
    currency: string;
    pettyMinor: number;
    attendants: {
        id: string;
        name: string;
        heldMinor: number;
        pendingMinor: number;
        holdings?: {
            currency: string;
            heldMinor: number;
            pendingMinor: number;
        }[];
    }[];
    safes: SafeRow[];
    transfers: Transfer[];
    deposits: {
        id: string;
        number: string;
        status: string;
        currency: string;
        amountMinor: number;
        bank: string;
        bankName: string | null;
        confirmedMinor: number | null;
        slip: string | null;
    }[];
    movements: {
        id: string;
        kind: string;
        amountMinor: number;
        currency: string;
        at: string;
        notes: string | null;
    }[];
    canManage: boolean;
    canSubmit: boolean;
    canConfirm: boolean;
    canReject: boolean;
    canTransfer: boolean;
    canSafes: boolean;
    canDeposit: boolean;
    canDepositConfirm: boolean;
    canReconcile: boolean;
    canAdjust: boolean;
    approvers: { id: string; name: string }[];
    currencies?: { code: string; name: string }[];
};

export default function Cash(props: Props) {
    const money = useMoney();
    const { shift, attendants, safes, transfers, deposits } = props;

    return (
        <>
            <Head title="Cash" />
            <PageHeader
                title="Cash"
                description="Cash moves from the attendant to the cashier, then the safe, then the bank. A handover is not a new sale."
            />
            <Panel className="mb-4">
                <PanelBody className="text-sm">
                    <p>
                        Drawer{' '}
                        {shift
                            ? `${shift.number}: ${money(shift.drawerMinor)}`
                            : 'no open shift'}
                        .
                    </p>
                    <p>Petty cash {money(props.pettyMinor)}.</p>
                    {attendants.map((attendant) => (
                        <p key={attendant.id}>
                            {attendant.name} holds {money(attendant.heldMinor)}.{' '}
                            {money(attendant.pendingMinor)} is waiting to be
                            counted.
                        </p>
                    ))}
                    {safes.map((safe) => (
                        <p key={safe.id}>
                            Safe {safe.code} ({safe.status}) holds{' '}
                            {money(safe.balanceMinor)}.
                        </p>
                    ))}
                </PanelBody>
            </Panel>
            {(shift?.drawers?.length ||
                attendants.some((row) => row.holdings?.length)) && (
                <Panel className="mb-4">
                    <PanelHeader
                        title="Cash by currency"
                        description="Each currency is its own balance. A shortage in one is not taken from another."
                    />
                    <PanelBody className="overflow-x-auto text-sm">
                        <table className="w-full">
                            <thead>
                                <tr className="text-left">
                                    <th className="py-1 pr-3">Currency</th>
                                    <th className="py-1 pr-3">Drawer</th>
                                    <th className="py-1 pr-3">
                                        Attendant holds
                                    </th>
                                    <th className="py-1">
                                        Waiting to be counted
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {(shift?.drawers ?? []).map((row) => {
                                    const held = attendants.reduce(
                                        (sum, attendant) =>
                                            sum +
                                            (attendant.holdings?.find(
                                                (line) =>
                                                    line.currency ===
                                                    row.currency,
                                            )?.heldMinor ?? 0),
                                        0,
                                    );
                                    const pending = attendants.reduce(
                                        (sum, attendant) =>
                                            sum +
                                            (attendant.holdings?.find(
                                                (line) =>
                                                    line.currency ===
                                                    row.currency,
                                            )?.pendingMinor ?? 0),
                                        0,
                                    );

                                    return (
                                        <tr key={row.currency}>
                                            <td className="py-1 pr-3">
                                                {row.currency}
                                            </td>
                                            <td className="py-1 pr-3">
                                                {formatMoney(
                                                    row.minor,
                                                    row.currency,
                                                )}
                                            </td>
                                            <td className="py-1 pr-3">
                                                {formatMoney(
                                                    held,
                                                    row.currency,
                                                )}
                                            </td>
                                            <td className="py-1">
                                                {formatMoney(
                                                    pending,
                                                    row.currency,
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </PanelBody>
                </Panel>
            )}
            {props.canSubmit && shift && attendants.length > 0 && (
                <Panel className="mb-4">
                    <PanelHeader title="Submit cash to the cashier" />
                    <PanelBody>
                        <Form
                            action="/petrol/cash/remittances"
                            method="post"
                            className="grid gap-3 sm:grid-cols-2"
                        >
                            <Field id="attendant_id" label="Attendant">
                                <select
                                    id="attendant_id"
                                    name="attendant_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {attendants.map((attendant) => (
                                        <option
                                            key={attendant.id}
                                            value={attendant.id}
                                        >
                                            {attendant.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <input
                                type="hidden"
                                name="cashier_id"
                                value={shift.openedBy}
                            />
                            <Field id="remit_amount" label="Amount">
                                <Input
                                    id="remit_amount"
                                    name="amount"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="remit_currency" label="Currency">
                                <CurrencySelect
                                    id="remit_currency"
                                    currencies={props.currencies}
                                    defaultValue={props.currency}
                                />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Submit</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            {props.canManage && shift && (
                <Panel className="mb-4">
                    <PanelHeader title="Fund petty cash" />
                    <PanelBody>
                        <Form {...petty.form()} className="flex gap-2">
                            <Field id="amount" label="Amount">
                                <Input
                                    id="amount"
                                    name="amount"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Move</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            {props.canSafes && (
                <Panel className="mb-4">
                    <PanelHeader title="Register a safe" />
                    <PanelBody>
                        <Form
                            action="/petrol/cash/safes"
                            method="post"
                            className="grid gap-3 sm:grid-cols-2"
                        >
                            <Field id="safe_name" label="Name">
                                <Input id="safe_name" name="name" required />
                            </Field>
                            <Field id="safe_code" label="Code">
                                <Input id="safe_code" name="code" required />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Save</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            {props.canTransfer && shift && safes.length > 0 && (
                <Panel className="mb-4">
                    <PanelHeader title="Move drawer cash to a safe" />
                    <PanelBody>
                        <Form
                            action="/petrol/cash/safes/transfer"
                            method="post"
                            className="grid gap-3 sm:grid-cols-2"
                        >
                            <Field id="safe_id" label="Safe">
                                <select
                                    id="safe_id"
                                    name="safe_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {safes.map((safe) => (
                                        <option key={safe.id} value={safe.id}>
                                            {safe.code}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field id="safe_amount" label="Amount">
                                <Input
                                    id="safe_amount"
                                    name="amount"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="safe_currency" label="Currency">
                                <CurrencySelect
                                    id="safe_currency"
                                    currencies={props.currencies}
                                    defaultValue={props.currency}
                                />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Send</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            {props.canTransfer && safes.length > 1 && (
                <Panel className="mb-4">
                    <PanelHeader title="Move cash between safes" />
                    <PanelBody>
                        <Form
                            action="/petrol/cash/safes/move"
                            method="post"
                            className="grid gap-3 sm:grid-cols-2"
                        >
                            <Field id="source_safe_id" label="From">
                                <select
                                    id="source_safe_id"
                                    name="source_safe_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {safes.map((safe) => (
                                        <option key={safe.id} value={safe.id}>
                                            {safe.code}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field id="destination_safe_id" label="To">
                                <select
                                    id="destination_safe_id"
                                    name="destination_safe_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {safes.map((safe) => (
                                        <option key={safe.id} value={safe.id}>
                                            {safe.code}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field id="between_amount" label="Amount">
                                <Input
                                    id="between_amount"
                                    name="amount"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="between_currency" label="Currency">
                                <CurrencySelect
                                    id="between_currency"
                                    currencies={props.currencies}
                                    defaultValue={props.currency}
                                />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Send</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            {props.canDeposit && safes.length > 0 && (
                <Panel className="mb-4">
                    <PanelHeader title="Prepare a bank deposit" />
                    <PanelBody>
                        <Form
                            action="/petrol/cash/deposits"
                            method="post"
                            className="grid gap-3 sm:grid-cols-2"
                        >
                            <Field id="deposit_safe_id" label="Safe">
                                <select
                                    id="deposit_safe_id"
                                    name="safe_id"
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    required
                                >
                                    {safes.map((safe) => (
                                        <option key={safe.id} value={safe.id}>
                                            {safe.code}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field id="bank_name" label="Bank name">
                                <Input id="bank_name" name="bank_name" />
                            </Field>
                            <Field
                                id="bank_reference"
                                label="Account reference"
                            >
                                <Input
                                    id="bank_reference"
                                    name="bank_reference"
                                    required
                                />
                            </Field>
                            <Field id="deposit_amount" label="Amount">
                                <Input
                                    id="deposit_amount"
                                    name="amount"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="deposit_currency" label="Currency">
                                <CurrencySelect
                                    id="deposit_currency"
                                    currencies={props.currencies}
                                    defaultValue={props.currency}
                                />
                            </Field>
                            <Field id="deposit_on" label="Date">
                                <Input
                                    id="deposit_on"
                                    name="deposit_on"
                                    type="date"
                                    required
                                />
                            </Field>
                            <Field id="slip_reference" label="Slip reference">
                                <Input
                                    id="slip_reference"
                                    name="slip_reference"
                                />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Prepare</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            {(props.canTransfer || props.canAdjust) &&
                shift &&
                props.approvers.length > 0 && (
                    <div className="mb-4 grid gap-4 lg:grid-cols-2">
                        {props.canTransfer && (
                            <Panel id="payouts">
                                <PanelHeader
                                    title="Pay cash out"
                                    description="Submitted cash stays where it is until another person approves it. Approval pays it once."
                                />
                                <PanelBody>
                                    <Form
                                        action="/petrol/cash/payouts"
                                        method="post"
                                        className="grid gap-3"
                                    >
                                        <Field
                                            id="payout_source"
                                            label="Source"
                                        >
                                            <select
                                                id="payout_source"
                                                name="safe_id"
                                                className="w-full rounded-md border px-3 py-2 text-sm"
                                            >
                                                <option value="">
                                                    Cashier drawer
                                                </option>
                                                {safes.map((safe) => (
                                                    <option
                                                        key={safe.id}
                                                        value={safe.id}
                                                    >
                                                        Safe {safe.code}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                        <Field
                                            id="payout_approver"
                                            label="Approver"
                                        >
                                            <select
                                                id="payout_approver"
                                                name="approver_id"
                                                className="w-full rounded-md border px-3 py-2 text-sm"
                                                required
                                            >
                                                {props.approvers.map(
                                                    (person) => (
                                                        <option
                                                            key={person.id}
                                                            value={person.id}
                                                        >
                                                            {person.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </Field>
                                        <Field id="category" label="Reason">
                                            <select
                                                id="category"
                                                name="category"
                                                className="w-full rounded-md border px-3 py-2 text-sm"
                                                required
                                            >
                                                <option value="Station expense">
                                                    Station expense
                                                </option>
                                                <option value="Wages">
                                                    Wages
                                                </option>
                                                <option value="Customer refund">
                                                    Customer refund
                                                </option>
                                                <option value="Other">
                                                    Other
                                                </option>
                                            </select>
                                        </Field>
                                        <Field id="payee" label="Payee">
                                            <Input
                                                id="payee"
                                                name="payee"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            id="payout_description"
                                            label="Description"
                                        >
                                            <Input
                                                id="payout_description"
                                                name="description"
                                            />
                                        </Field>
                                        <Field
                                            id="receipt"
                                            label="Receipt reference"
                                        >
                                            <Input
                                                id="receipt"
                                                name="receipt"
                                            />
                                        </Field>
                                        <Field
                                            id="expense_id"
                                            label="Existing expenditure"
                                        >
                                            <Input
                                                id="expense_id"
                                                name="expense_id"
                                                placeholder="Leave blank unless it is already recorded"
                                            />
                                        </Field>
                                        <Field
                                            id="payout_amount"
                                            label="Amount"
                                        >
                                            <Input
                                                id="payout_amount"
                                                name="amount"
                                                inputMode="decimal"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            id="payout_currency"
                                            label="Currency"
                                        >
                                            <CurrencySelect
                                                id="payout_currency"
                                                currencies={props.currencies}
                                                defaultValue={props.currency}
                                            />
                                        </Field>
                                        <Button type="submit">
                                            Submit payout
                                        </Button>
                                    </Form>
                                </PanelBody>
                            </Panel>
                        )}
                        {props.canAdjust && (
                            <Panel id="adjustments">
                                <PanelHeader
                                    title="Drawer adjustment"
                                    description="The count stays on record. Approval changes the drawer once and keeps the explanation."
                                />
                                <PanelBody>
                                    <Form
                                        action="/petrol/cash/adjustments"
                                        method="post"
                                        className="grid gap-3"
                                    >
                                        <Field
                                            id="adjust_approver"
                                            label="Approver"
                                        >
                                            <select
                                                id="adjust_approver"
                                                name="approver_id"
                                                className="w-full rounded-md border px-3 py-2 text-sm"
                                                required
                                            >
                                                {props.approvers.map(
                                                    (person) => (
                                                        <option
                                                            key={person.id}
                                                            value={person.id}
                                                        >
                                                            {person.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </Field>
                                        <Field id="adjust_type" label="Type">
                                            <select
                                                id="adjust_type"
                                                name="type"
                                                className="w-full rounded-md border px-3 py-2 text-sm"
                                                required
                                            >
                                                <option value="opening float correction">
                                                    Opening float correction
                                                </option>
                                                <option value="counting correction">
                                                    Counting correction
                                                </option>
                                                <option value="verified shortage">
                                                    Verified shortage
                                                </option>
                                                <option value="verified overage">
                                                    Verified overage
                                                </option>
                                                <option value="other">
                                                    Other
                                                </option>
                                            </select>
                                        </Field>
                                        <Field id="direction" label="Direction">
                                            <select
                                                id="direction"
                                                name="direction"
                                                className="w-full rounded-md border px-3 py-2 text-sm"
                                                required
                                            >
                                                <option value="out">
                                                    Reduce the drawer
                                                </option>
                                                <option value="in">
                                                    Increase the drawer
                                                </option>
                                            </select>
                                        </Field>
                                        <Field id="reason" label="Reason">
                                            <Input
                                                id="reason"
                                                name="reason"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            id="adjust_amount"
                                            label="Amount"
                                        >
                                            <Input
                                                id="adjust_amount"
                                                name="amount"
                                                inputMode="decimal"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            id="adjust_currency"
                                            label="Currency"
                                        >
                                            <CurrencySelect
                                                id="adjust_currency"
                                                currencies={props.currencies}
                                                defaultValue={props.currency}
                                            />
                                        </Field>
                                        <Button type="submit">
                                            Submit adjustment
                                        </Button>
                                    </Form>
                                </PanelBody>
                            </Panel>
                        )}
                    </div>
                )}
            {props.canReconcile &&
                (attendants.length > 0 || safes.length > 0 || shift) && (
                    <Panel className="mb-4">
                        <PanelHeader title="Count cash" />
                        <PanelBody>
                            <Form
                                action="/petrol/cash/counts"
                                method="post"
                                className="grid gap-3 sm:grid-cols-2"
                            >
                                <Field id="holder" label="Who holds it">
                                    <select
                                        id="holder"
                                        name="holder"
                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                        required
                                    >
                                        {shift && (
                                            <option
                                                value={`cashier:${shift.openedBy}`}
                                            >
                                                Cashier drawer
                                            </option>
                                        )}
                                        {attendants.map((attendant) => (
                                            <option
                                                key={attendant.id}
                                                value={`attendant:${attendant.id}`}
                                            >
                                                {attendant.name}
                                            </option>
                                        ))}
                                        {safes.map((safe) => (
                                            <option
                                                key={safe.id}
                                                value={`safe:${safe.id}`}
                                            >
                                                Safe {safe.code}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field id="counted" label="Counted amount">
                                    <Input
                                        id="counted"
                                        name="counted"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Field id="count_currency" label="Currency">
                                    <CurrencySelect
                                        id="count_currency"
                                        currencies={props.currencies}
                                        defaultValue={props.currency}
                                    />
                                </Field>
                                <div className="self-end">
                                    <Button type="submit">Record count</Button>
                                </div>
                            </Form>
                        </PanelBody>
                    </Panel>
                )}
            <Panel className="mb-4">
                <PanelHeader title="Handovers" />
                <PanelBody>
                    <ul className="grid gap-3 text-sm">
                        {transfers.map((row) => (
                            <li key={row.id} className="border-b pb-3">
                                <p>
                                    {row.number} · {row.kind} · {row.status} ·{' '}
                                    {formatMoney(
                                        row.declaredMinor,
                                        row.currency,
                                    )}
                                    {row.countedMinor !== null
                                        ? ` counted ${formatMoney(row.countedMinor, row.currency)}`
                                        : ''}
                                </p>
                                {row.notes && (
                                    <p className="text-muted-foreground">
                                        {row.notes}
                                    </p>
                                )}
                                {row.status === 'pending' &&
                                    (props.canConfirm || props.canReject) && (
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {props.canConfirm && (
                                                <Form
                                                    action={`/petrol/cash/transfers/${row.id}/confirm`}
                                                    method="post"
                                                    className="flex gap-2"
                                                >
                                                    <Input
                                                        name="counted"
                                                        inputMode="decimal"
                                                        placeholder="Counted"
                                                        required
                                                    />
                                                    <Button type="submit">
                                                        Confirm
                                                    </Button>
                                                </Form>
                                            )}
                                            {props.canReject && (
                                                <Form
                                                    action={`/petrol/cash/transfers/${row.id}/reject`}
                                                    method="post"
                                                    className="flex gap-2"
                                                >
                                                    <Input
                                                        name="notes"
                                                        placeholder="Why it was rejected"
                                                        required
                                                    />
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                    >
                                                        Reject
                                                    </Button>
                                                </Form>
                                            )}
                                        </div>
                                    )}
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
            <Panel className="mb-4">
                <PanelHeader title="Bank deposits" />
                <PanelBody>
                    <ul className="grid gap-3 text-sm">
                        {deposits.map((row) => (
                            <li key={row.id}>
                                {row.number} · {row.status} ·{' '}
                                {row.bankName ? `${row.bankName} · ` : ''}
                                {row.bank} ·{' '}
                                {formatMoney(row.amountMinor, row.currency)}
                                {row.confirmedMinor !== null
                                    ? ` · bank counted ${formatMoney(row.confirmedMinor, row.currency)}`
                                    : ''}
                                {row.slip ? ` · slip ${row.slip}` : ''}
                                {row.status === 'prepared' &&
                                    props.canDeposit && (
                                        <Form
                                            action={`/petrol/cash/deposits/${row.id}/submit`}
                                            method="post"
                                            className="mt-2"
                                        >
                                            <Button
                                                type="submit"
                                                variant="outline"
                                            >
                                                Send to the bank
                                            </Button>
                                        </Form>
                                    )}
                                {row.status === 'submitted' &&
                                    props.canDepositConfirm && (
                                        <div className="mt-2 flex gap-2">
                                            <Form
                                                action={`/petrol/cash/deposits/${row.id}/confirm`}
                                                method="post"
                                                className="flex gap-2"
                                            >
                                                <Input
                                                    name="confirmed"
                                                    inputMode="decimal"
                                                    placeholder="Bank counted"
                                                />
                                                <Button type="submit">
                                                    Bank confirmed
                                                </Button>
                                            </Form>
                                            <Form
                                                action={`/petrol/cash/deposits/${row.id}/reject`}
                                                method="post"
                                                className="flex gap-2"
                                            >
                                                <Input
                                                    name="notes"
                                                    placeholder="Why it came back"
                                                    required
                                                />
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                >
                                                    Reject
                                                </Button>
                                            </Form>
                                        </div>
                                    )}
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
            <Panel>
                <PanelHeader title="Drawer movements" />
                <PanelBody>
                    <ul className="text-sm">
                        {props.movements.map((row) => (
                            <li key={row.id}>
                                {row.kind}{' '}
                                {formatMoney(row.amountMinor, row.currency)}
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
        </>
    );
}
