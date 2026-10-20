import { Form, Head, Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Amount } from '@/components/amount';
import { Field } from '@/components/field';
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
import { useCan } from '@/lib/access';
import { useAmountStep } from '@/lib/format';
import { destroy, update } from '@/routes/budgets';
import { budgetVsActual } from '@/routes/reports';
import { toMinor } from '@/components/lines-editor';

type Props = {
    budget: {
        id: string;
        name: string;
        year: string;
        costCentre: string | null;
    };
    periods: { number: number; label: string }[];
    accounts: { id: string; label: string; type: 'revenue' | 'expense' }[];
    /** Account id → period number → amount (minor units). */
    amounts: Record<string, Record<string, number>>;
};

/**
 * One budget as a grid: an amount per revenue and expense account and month.
 */
export default function ShowBudget({
    budget,
    periods,
    accounts,
    amounts,
}: Props) {
    const can = useCan();
    const manage = can('finance.budgets.manage');
    const step = useAmountStep();
    const scale = step === '1' ? 1 : 100;
    const major = (minor: number) =>
        (minor / scale).toFixed(step === '1' ? 0 : 2);

    // Typed values, as strings, by account and period.
    const [values, setValues] = useState<
        Record<string, Record<number, string>>
    >(() =>
        Object.fromEntries(
            accounts.map((account) => [
                account.id,
                Object.fromEntries(
                    periods.map((period) => {
                        const minor = amounts[account.id]?.[period.number];

                        return [
                            period.number,
                            minor === undefined ? '' : major(minor),
                        ];
                    }),
                ),
            ]),
        ),
    );

    const set = (account: string, period: number, value: string) =>
        setValues((current) => ({
            ...current,
            [account]: { ...current[account], [period]: value },
        }));
    const parse = (value: string) =>
        value.startsWith('-')
            ? -toMinor(value.slice(1), step)
            : toMinor(value, step);
    const rowTotal = (account: string) =>
        periods.reduce(
            (sum, period) =>
                sum + parse(values[account]?.[period.number] ?? ''),
            0,
        );
    const columnTotal = (type: string, period: number) =>
        accounts
            .filter((account) => account.type === type)
            .reduce(
                (sum, account) =>
                    sum + parse(values[account.id]?.[period] ?? ''),
                0,
            );
    const typeTotal = (type: string) =>
        accounts
            .filter((account) => account.type === type)
            .reduce((sum, account) => sum + rowTotal(account.id), 0);

    /** Spreads a yearly amount evenly, the remainder in the last month. */
    const spread = (account: string, yearly: string) => {
        const total = parse(yearly);
        const each = Math.trunc(total / periods.length);
        setValues((current) => ({
            ...current,
            [account]: Object.fromEntries(
                periods.map((period, index) => [
                    period.number,
                    major(
                        index === periods.length - 1
                            ? total - each * (periods.length - 1)
                            : each,
                    ),
                ]),
            ),
        }));
    };

    const section = (type: 'revenue' | 'expense', title: string) => (
        <>
            <TableRow className="hover:bg-transparent">
                <TableCell
                    colSpan={periods.length + 2}
                    className="pt-4 font-semibold"
                >
                    {title}
                </TableCell>
            </TableRow>
            {accounts
                .filter((account) => account.type === type)
                .map((account) => (
                    <TableRow key={account.id}>
                        <TableCell className="sticky left-0 min-w-56 bg-card">
                            {account.label}
                            {manage && (
                                <Input
                                    aria-label={`Spread a yearly amount for ${account.label}`}
                                    placeholder="Spread a year…"
                                    inputMode="decimal"
                                    className="mt-1 h-7 w-36 text-xs"
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            spread(
                                                account.id,
                                                event.currentTarget.value,
                                            );
                                            event.currentTarget.value = '';
                                        }
                                    }}
                                />
                            )}
                        </TableCell>
                        {periods.map((period) => (
                            <TableCell key={period.number} className="p-1">
                                <Input
                                    name={`amounts[${account.id}][${period.number}]`}
                                    value={
                                        values[account.id]?.[period.number] ??
                                        ''
                                    }
                                    onChange={(event) =>
                                        set(
                                            account.id,
                                            period.number,
                                            event.target.value,
                                        )
                                    }
                                    inputMode="decimal"
                                    disabled={!manage}
                                    className="w-24 text-right tabular-nums"
                                    aria-label={`${account.label}, ${period.label}`}
                                />
                            </TableCell>
                        ))}
                        <TableCell className="text-right font-medium">
                            <Amount minor={rowTotal(account.id)} blankZero />
                        </TableCell>
                    </TableRow>
                ))}
            <TableRow className="hover:bg-transparent">
                <TableCell className="sticky left-0 bg-card font-medium">
                    Total {title.toLowerCase()}
                </TableCell>
                {periods.map((period) => (
                    <TableCell
                        key={period.number}
                        className="text-right font-medium"
                    >
                        <Amount
                            minor={columnTotal(type, period.number)}
                            blankZero
                        />
                    </TableCell>
                ))}
                <TableCell className="text-right font-semibold">
                    <Amount minor={typeTotal(type)} />
                </TableCell>
            </TableRow>
        </>
    );

    return (
        <>
            <Head title={`Budget ${budget.name}`} />
            <PageHeader
                title={`Budget ${budget.name}`}
                description={`${budget.year} · ${budget.costCentre ?? 'Whole organization'}`}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link
                                href={budgetVsActual({
                                    query: { budget: budget.id },
                                })}
                            >
                                Budget vs actual
                            </Link>
                        </Button>
                        {manage && (
                            <Form {...destroy.form(budget.id)}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        disabled={processing}
                                    >
                                        <Trash2
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Delete
                                    </Button>
                                )}
                            </Form>
                        )}
                    </>
                }
            />

            <Form
                {...update.form(budget.id)}
                options={{ preserveScroll: true }}
            >
                {({ errors, processing }) => (
                    <Panel>
                        {manage && (
                            <PanelBody className="flex flex-wrap items-end gap-3">
                                <Field
                                    id="name"
                                    label="Name"
                                    error={errors.name}
                                    className="w-64"
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={budget.name}
                                        required
                                        maxLength={100}
                                    />
                                </Field>
                                <Button type="submit" disabled={processing}>
                                    Save budget
                                </Button>
                                {Object.entries(errors)
                                    .filter(([key]) => key !== 'name')
                                    .slice(0, 1)
                                    .map(([key, message]) => (
                                        <p
                                            key={key}
                                            className="text-sm text-destructive"
                                        >
                                            {message}
                                        </p>
                                    ))}
                            </PanelBody>
                        )}
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="sticky left-0 bg-card">
                                            Account
                                        </TableHead>
                                        {periods.map((period) => (
                                            <TableHead
                                                key={period.number}
                                                className="text-right whitespace-nowrap"
                                            >
                                                {period.label}
                                            </TableHead>
                                        ))}
                                        <TableHead className="text-right">
                                            Year
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <Fragment>
                                        {section('revenue', 'Revenue')}
                                        {section('expense', 'Expenses')}
                                    </Fragment>
                                </TableBody>
                                <TableFooter>
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell className="sticky left-0 bg-muted font-semibold">
                                            Planned profit
                                        </TableCell>
                                        {periods.map((period) => (
                                            <TableCell
                                                key={period.number}
                                                className="text-right font-semibold"
                                            >
                                                <Amount
                                                    minor={
                                                        columnTotal(
                                                            'revenue',
                                                            period.number,
                                                        ) -
                                                        columnTotal(
                                                            'expense',
                                                            period.number,
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        ))}
                                        <TableCell className="text-right font-semibold">
                                            <Amount
                                                minor={
                                                    typeTotal('revenue') -
                                                    typeTotal('expense')
                                                }
                                            />
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </div>
                    </Panel>
                )}
            </Form>
        </>
    );
}
