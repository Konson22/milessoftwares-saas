import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { Field } from '@/components/field';
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
import { settings as journalSettings } from '@/routes/journal-approvals';
import { settings as paymentSettings } from '@/routes/payment-requests';
import { settings as pettyCashSettings } from '@/routes/petty-cash';

type Props = {
    canChange: boolean;
    journals: { enabled: boolean; threshold: string };
    payments: { threshold: string; review: boolean };
    pettyCash: { max: string };
    history: {
        id: string;
        at: string;
        by: string | null;
        rules: string;
        before: string;
        after: string;
    }[];
};

/**
 * When journals and payments need a second person. Kept apart from the lists
 * of what is waiting, so setting the rules does not mean seeing the money.
 * Without finance.approvals.configure the page is read only (auditors).
 */
export default function ApprovalRules({
    canChange,
    journals,
    payments,
    pettyCash,
    history,
}: Props) {
    return (
        <>
            <Head title="Approval rules" />
            <PageHeader
                title="Approval rules"
                description={
                    canChange
                        ? 'What needs a second person before it reaches the books. Changes do not affect anything already waiting.'
                        : 'What needs a second person before it reaches the books. You can see these rules but not change them.'
                }
            />
            <AlwaysOn />
            <PaymentRules setting={payments} canChange={canChange} />
            <JournalRules setting={journals} canChange={canChange} />
            <PettyCashRules setting={pettyCash} canChange={canChange} />
            <History history={history} />
        </>
    );
}

/**
 * The controls no setting turns off, owners included.
 */
function AlwaysOn() {
    return (
        <Panel className="mb-3">
            <PanelHeader
                title="Always on"
                description="These apply to everyone, organization owners included, and cannot be turned off."
            />
            <PanelBody>
                <ul className="grid list-disc gap-1 pl-5 text-sm">
                    <li>
                        Money leaves a bank only through a payment request, from
                        the approval limit up. That includes tax settlements,
                        journals and reversals that take money out of a bank,
                        and outgoing statement lines.
                    </li>
                    <li>
                        Nobody reviews or approves their own payment request, or
                        approves one they reviewed.
                    </li>
                    <li>
                        Nobody disburses a payment they prepared, reviewed or
                        approved.
                    </li>
                    <li>
                        Nobody approves their own journal, or a recurring
                        journal they set up or last changed.
                    </li>
                    <li>
                        A request is never edited: to change it, withdraw it and
                        raise another.
                    </li>
                    <li>
                        Cash and petty cash accounts never pay out more than
                        they hold. Only a fund's custodian pays from it; someone
                        else checks its vouchers and counts its cash.
                    </li>
                </ul>
            </PanelBody>
        </Panel>
    );
}

function PaymentRules({
    setting,
    canChange,
}: {
    setting: Props['payments'];
    canChange: boolean;
}) {
    return (
        <Panel className="mb-3">
            <PanelHeader
                title="Payments"
                description="Apply to every payment request."
            />
            <PanelBody>
                <Form {...paymentSettings.form()}>
                    {({ processing, errors }) => (
                        <fieldset disabled={!canChange} className="grid gap-3">
                            <div className="grid gap-2 text-sm">
                                <Rule
                                    name="payment_review"
                                    checked={setting.review}
                                    label="Review before approval"
                                    hint="A reviewer checks each request before it reaches an approver."
                                />
                            </div>
                            <Field
                                id="payment-threshold"
                                label="Approval limit"
                                error={errors.threshold}
                                hint="Smaller payments need no approval. Blank: every payment does."
                                className="max-w-64"
                            >
                                <Input
                                    id="payment-threshold"
                                    name="threshold"
                                    inputMode="decimal"
                                    defaultValue={setting.threshold}
                                    className="text-right tabular-nums"
                                />
                            </Field>
                            {canChange && (
                                <div>
                                    <Button type="submit" disabled={processing}>
                                        Save payment rules
                                    </Button>
                                </div>
                            )}
                        </fieldset>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

function PettyCashRules({
    setting,
    canChange,
}: {
    setting: Props['pettyCash'];
    canChange: boolean;
}) {
    return (
        <Panel className="mb-3">
            <PanelHeader
                title="Petty cash"
                description="The organization's petty cash policy: the most one voucher may be."
            />
            <PanelBody>
                <Form {...pettyCashSettings.form()}>
                    {({ processing, errors }) => (
                        <fieldset
                            disabled={!canChange}
                            className="flex flex-wrap items-end gap-3"
                        >
                            <Field
                                id="petty-cash-max"
                                label="Most one voucher may be"
                                error={errors.petty_cash_max}
                                hint="Blank: no voucher can be recorded until a limit is set."
                                className="max-w-64"
                            >
                                <Input
                                    id="petty-cash-max"
                                    name="petty_cash_max"
                                    inputMode="decimal"
                                    defaultValue={setting.max}
                                    className="text-right tabular-nums"
                                />
                            </Field>
                            {canChange && (
                                <Button type="submit" disabled={processing}>
                                    Save petty cash limit
                                </Button>
                            )}
                        </fieldset>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

function JournalRules({
    setting,
    canChange,
}: {
    setting: Props['journals'];
    canChange: boolean;
}) {
    const step = useAmountStep();
    const [enabled, setEnabled] = useState(setting.enabled);

    return (
        <Panel className="mb-3">
            <PanelHeader
                title="Journals"
                description="For manual and recurring journals. Journals from invoices, bills, payments, banking and products post as before."
            />
            <PanelBody>
                <Form {...journalSettings.form()}>
                    {({ processing, errors }) => (
                        <fieldset
                            disabled={!canChange}
                            className="flex flex-wrap items-end gap-3"
                        >
                            <label className="flex h-9 items-center gap-2 text-sm">
                                <input
                                    type="hidden"
                                    name="journal_approval"
                                    value="0"
                                />
                                <input
                                    type="checkbox"
                                    name="journal_approval"
                                    value="1"
                                    checked={enabled}
                                    onChange={(event) =>
                                        setEnabled(event.target.checked)
                                    }
                                />
                                Manual journals need a second person's approval
                            </label>
                            {enabled && (
                                <Field
                                    id="journal-threshold"
                                    label="From this amount up"
                                    error={errors.threshold}
                                    hint="Blank: every manual journal"
                                >
                                    <Input
                                        id="journal-threshold"
                                        name="threshold"
                                        type="number"
                                        min="0"
                                        step={step}
                                        defaultValue={setting.threshold}
                                        className="w-40"
                                    />
                                </Field>
                            )}
                            {canChange && (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Save journal rules
                                </Button>
                            )}
                        </fieldset>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

function History({ history }: { history: Props['history'] }) {
    return (
        <Panel>
            <PanelHeader
                title="Changes"
                description="The latest changes to these rules. Each payment request and held journal also keeps the rules that applied when it was raised."
            />
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>When</TableHead>
                        <TableHead>By</TableHead>
                        <TableHead>Rules</TableHead>
                        <TableHead>Before</TableHead>
                        <TableHead>After</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {history.length === 0 && (
                        <TableEmpty colSpan={5}>
                            No changes recorded yet.
                        </TableEmpty>
                    )}
                    {history.map((change) => (
                        <TableRow key={change.id}>
                            <TableCell className="tabular-nums">
                                {new Date(change.at).toLocaleString()}
                            </TableCell>
                            <TableCell>{change.by ?? '—'}</TableCell>
                            <TableCell>{change.rules}</TableCell>
                            <TableCell className="text-muted-foreground">
                                {change.before}
                            </TableCell>
                            <TableCell>{change.after}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </Panel>
    );
}

function Rule({
    name,
    checked,
    label,
    hint,
}: {
    name: string;
    checked: boolean;
    label: string;
    hint: string;
}) {
    return (
        <label className="flex items-start gap-2">
            <input type="hidden" name={name} value="0" />
            <input
                type="checkbox"
                name={name}
                value="1"
                defaultChecked={checked}
                className="mt-1"
            />
            <span>
                <span className="font-medium">{label}</span>
                <span className="block text-xs text-muted-foreground">
                    {hint}
                </span>
            </span>
        </label>
    );
}
