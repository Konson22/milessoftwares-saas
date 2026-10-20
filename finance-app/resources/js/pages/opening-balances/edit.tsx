import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { toMinor } from '@/components/lines-editor';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useAmountStep } from '@/lib/format';
import { show as showJournal } from '@/routes/journals';
import { update } from '@/routes/opening-balances';
import { index as periods } from '@/routes/periods';

type Props = {
    date: string | null;
    lockedUntil: string | null;
    journal: { id: string; number: string } | null;
    accounts: {
        id: string;
        code: string;
        name: string;
        type: string;
        balanceMinor: number;
    }[];
};

type Row = { debit: string; credit: string };

const TYPES = [
    { type: 'asset', title: 'Assets' },
    { type: 'liability', title: 'Liabilities' },
    { type: 'equity', title: 'Equity' },
];

/**
 * What each balance sheet account held on the day the books start. The
 * difference goes to opening balance equity.
 */
export default function OpeningBalances({
    date,
    lockedUntil,
    journal,
    accounts,
}: Props) {
    const step = useAmountStep();
    const { errors } = usePage().props;
    const typed = (minor: number) =>
        minor === 0
            ? ''
            : (Math.abs(minor) / (step === '1' ? 1 : 100)).toFixed(
                  step === '1' ? 0 : 2,
              );
    const [rows, setRows] = useState<Record<string, Row>>(() =>
        Object.fromEntries(
            accounts.map((account) => [
                account.id,
                {
                    debit:
                        account.balanceMinor > 0
                            ? typed(account.balanceMinor)
                            : '',
                    credit:
                        account.balanceMinor < 0
                            ? typed(account.balanceMinor)
                            : '',
                },
            ]),
        ),
    );
    const set = (id: string, side: keyof Row, value: string) =>
        setRows((current) => ({
            ...current,
            [id]: { ...current[id], [side]: value },
        }));

    const debits = Object.values(rows).reduce(
        (sum, row) => sum + toMinor(row.debit, step),
        0,
    );
    const credits = Object.values(rows).reduce(
        (sum, row) => sum + toMinor(row.credit, step),
        0,
    );
    const difference = debits - credits;
    const locked = date !== null && lockedUntil !== null && lockedUntil >= date;

    if (date === null) {
        return (
            <>
                <Head title="Opening balances" />
                <PageHeader title="Opening balances" />
                <Panel>
                    <EmptyState
                        icon={CalendarRange}
                        title="Set up your first financial year"
                        action={
                            <Button asChild>
                                <Link href={periods()}>Periods & close</Link>
                            </Button>
                        }
                    >
                        Opening balances are dated the first day of your first
                        financial year.
                    </EmptyState>
                </Panel>
            </>
        );
    }

    return (
        <>
            <Head title="Opening balances" />
            <PageHeader
                title="Opening balances"
                description={
                    <>
                        What each account held on {date}, the day your books in
                        Miles start.
                        {journal && (
                            <>
                                {' Posted as '}
                                <Link
                                    href={showJournal(journal.id)}
                                    className="font-mono text-primary hover:underline"
                                >
                                    {journal.number}
                                </Link>
                                ; saving again replaces it.
                            </>
                        )}
                    </>
                }
            />

            {locked && (
                <p className="mb-4 text-sm text-destructive">
                    The first month is locked. Unlock it under Periods & close
                    to change opening balances.
                </p>
            )}
            {errors.balances && (
                <p className="mb-4 text-sm text-destructive">
                    {errors.balances}
                </p>
            )}
            {errors.entry_date && (
                <p className="mb-4 text-sm text-destructive">
                    {errors.entry_date}
                </p>
            )}

            <Form {...update.form()}>
                {({ processing }) => (
                    <Panel>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Account</TableHead>
                                    <TableHead className="w-40 text-right">
                                        Debit
                                    </TableHead>
                                    <TableHead className="w-40 text-right">
                                        Credit
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            {TYPES.map(({ type, title }) => (
                                <TableBody key={type}>
                                    <TableRow className="bg-muted/40 hover:bg-muted/40">
                                        <TableCell
                                            colSpan={3}
                                            className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                        >
                                            {title}
                                        </TableCell>
                                    </TableRow>
                                    {accounts
                                        .filter(
                                            (account) => account.type === type,
                                        )
                                        .map((account) => {
                                            const index =
                                                accounts.indexOf(account);
                                            const error =
                                                errors[
                                                    `balances.${account.id}`
                                                ] ??
                                                errors[
                                                    `balances.${index}.debit`
                                                ] ??
                                                errors[
                                                    `balances.${index}.credit`
                                                ];

                                            return (
                                                <TableRow key={account.id}>
                                                    <TableCell>
                                                        <input
                                                            type="hidden"
                                                            name={`balances[${index}][account_id]`}
                                                            value={account.id}
                                                        />
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {account.code}
                                                        </span>{' '}
                                                        {account.name}
                                                        {error && (
                                                            <p className="text-sm text-destructive">
                                                                {error}
                                                            </p>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Input
                                                            name={`balances[${index}][debit]`}
                                                            aria-label={`${account.name} debit`}
                                                            inputMode="decimal"
                                                            className="text-right tabular-nums"
                                                            value={
                                                                rows[account.id]
                                                                    .debit
                                                            }
                                                            onChange={(event) =>
                                                                set(
                                                                    account.id,
                                                                    'debit',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            disabled={locked}
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <Input
                                                            name={`balances[${index}][credit]`}
                                                            aria-label={`${account.name} credit`}
                                                            inputMode="decimal"
                                                            className="text-right tabular-nums"
                                                            value={
                                                                rows[account.id]
                                                                    .credit
                                                            }
                                                            onChange={(event) =>
                                                                set(
                                                                    account.id,
                                                                    'credit',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            disabled={locked}
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                </TableBody>
                            ))}
                            <TableFooter>
                                <TableRow>
                                    <TableCell>
                                        Opening balance equity (the difference)
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={
                                                difference < 0 ? -difference : 0
                                            }
                                            blankZero
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={
                                                difference > 0 ? difference : 0
                                            }
                                            blankZero
                                        />
                                    </TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell className="font-semibold">
                                        Total
                                    </TableCell>
                                    <TableCell className="text-right font-semibold">
                                        <Amount
                                            minor={Math.max(debits, credits)}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right font-semibold">
                                        <Amount
                                            minor={Math.max(debits, credits)}
                                        />
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                        <PanelBody className="border-t">
                            <Button
                                type="submit"
                                disabled={processing || locked}
                            >
                                Post opening balances
                            </Button>
                        </PanelBody>
                    </Panel>
                )}
            </Form>
        </>
    );
}
