import { Form, Head } from '@inertiajs/react';
import { ListChecks, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
import { Field, Select } from '@/components/field';
import { useCan } from '@/lib/access';
import { destroy, store } from '@/routes/bank-rules';

type Option = { id: string; label: string };

type Props = {
    rules: {
        id: string;
        bankAccount: string | null;
        contains: string;
        direction: string;
        account: string;
        memo: string | null;
    }[];
    banks: Option[];
    accounts: Option[];
};

const DIRECTIONS: Record<string, string> = {
    in: 'Money in',
    out: 'Money out',
    any: 'Either',
};

/**
 * Bank rules: statement lines whose description contains a text, and that
 * nothing in the books matches, are recorded against an account.
 */
export default function BankRules({ rules, banks, accounts }: Props) {
    const can = useCan();
    const manage = can('finance.banking.reconcile');
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title="Bank rules" />
            <PageHeader
                title="Bank rules"
                description="Used by “Match automatically” for statement lines nothing in the books matches, first rule first; also suggested on each line."
                actions={
                    manage && (
                        <Button onClick={() => setAdding((value) => !value)}>
                            <Plus className="size-4" aria-hidden />
                            New rule
                        </Button>
                    )
                }
            />

            {adding && (
                <Panel className="mb-3">
                    <PanelHeader title="New rule" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            onSuccess={() => setAdding(false)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="contains"
                                        label="Description contains"
                                        error={errors.contains}
                                        hint="For example: SMS CHARGE"
                                    >
                                        <Input
                                            id="contains"
                                            name="contains"
                                            required
                                            maxLength={100}
                                        />
                                    </Field>
                                    <Field
                                        id="direction"
                                        label="For"
                                        error={errors.direction}
                                    >
                                        <Select
                                            id="direction"
                                            name="direction"
                                            defaultValue="out"
                                        >
                                            {Object.entries(DIRECTIONS).map(
                                                ([value, label]) => (
                                                    <option
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </option>
                                                ),
                                            )}
                                        </Select>
                                    </Field>
                                    <Field
                                        id="bank_account_id"
                                        label="On"
                                        error={errors.bank_account_id}
                                    >
                                        <Select
                                            id="bank_account_id"
                                            name="bank_account_id"
                                            defaultValue=""
                                        >
                                            <option value="">
                                                Every bank account
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
                                        id="account_id"
                                        label="Record against"
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
                                        id="memo"
                                        label="Memo"
                                        error={errors.memo}
                                        className="sm:col-span-3"
                                        hint="Blank: the statement line's description"
                                    >
                                        <Input
                                            id="memo"
                                            name="memo"
                                            maxLength={255}
                                        />
                                    </Field>
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add rule
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                {rules.length === 0 ? (
                    <EmptyState icon={ListChecks} title="No rules yet">
                        Add one for regular lines such as bank charges or mobile
                        money fees.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Description contains</TableHead>
                                <TableHead>For</TableHead>
                                <TableHead>On</TableHead>
                                <TableHead>Record against</TableHead>
                                <TableHead>Memo</TableHead>
                                {manage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rules.map((rule) => (
                                <TableRow key={rule.id}>
                                    <TableCell className="font-medium">
                                        “{rule.contains}”
                                    </TableCell>
                                    <TableCell>
                                        {DIRECTIONS[rule.direction]}
                                    </TableCell>
                                    <TableCell>
                                        {rule.bankAccount ??
                                            'Every bank account'}
                                    </TableCell>
                                    <TableCell>{rule.account}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {rule.memo}
                                    </TableCell>
                                    {manage && (
                                        <TableCell className="text-right">
                                            <Form {...destroy.form(rule.id)}>
                                                {({ processing }) => (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        type="submit"
                                                        disabled={processing}
                                                        aria-label="Remove rule"
                                                    >
                                                        <Trash2
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                    </Button>
                                                )}
                                            </Form>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
